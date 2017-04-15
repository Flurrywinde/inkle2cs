<?php
/*
inkle2cs.php: Grab an Inkle story from the web and convert it to choicescript.

$title: The game's title

TO DO:

*** If a stitch has neither divert nor linkpath (i.e. end of story), make_story_array() seems to not put the text in. Then, make_cs_array() calls process_choices() on it despite there not being any. process_choices() should error check for this condition and abort. DONE. (Del after verification) Still not working. Doesn't put last stitch in.
* Graphvis code missing final ; and last } needs to be on the next line down.
* Error check: Page can't be curled
* Make the story data parser more bulletproof (yeesh, it's bad now). Needs mondo testing.
* Is $this_elt sometimes an array, sometimes the index to $story_array?
* Parse CS vars if found in Inkle text (also CS unselectable/hidden choices and other CS features)
* Input can be CS (can output CS again, but it will be cleaned up)
* Output choices: twee, sql, back to Inkle (but can't use?)
* Ability to rename a label (and it updates everywhere)
* Make complicated Inkle code to test. How sections work? New CS file?
* CS IDE. Keeps backup sql of all changes. Like Inkle site but var manip in text or choices.
* NOT NOW: Add fields to $cs_array that track all possible variable values and what path was taken
* NOT NOW: level should be CS level, not Inkle level (i.e. don't count new paragraph diverts as a change in level)
* NOT NOW: Option for short choices to go inline. Also, a code to indicate something should be inline.
* NOT NOW: Option to make labels even for diverts with only one parent

BUGS:

* Proc flags and other inline things (like italics)
	* "${number:shawn_money}" caused javascript error: line 285: invalid ${} variable substitution at letter 109
	* $[number:jake_money], [value:shawn_money] -- strange, sometimes as $ before, sometimes {} vs []. Meaning?
	parse_inkle_weirdness currently converts ${} and {} to []. 
	
*/

//////////////
// SETTINGS //
//////////////
$inkle_game_url = "http://writer.inklestudios.com/stories/8hmz";
$output_file = 'inkle2cs.txt';
$debug = true;
$story_array = array(); // All stitches extracted from Inkle code (out of order, no extra data)
$cs_array = array();	// Final story data in correct order, with extra data like level and parents
$cs_text = '';			// Choicescript to output
$proc_list = array(); 	// List of story elements to process in order. This is to go level by level rather that finish each leg first. Each member is an array such that:
						//		['name'] = Name of the stitch
						//		['level'] = Level of the stitch
$parents_array = array();	// List of parents of each stitch. Key is stitch's name. Each member is an array of three arrays: all_parents, diverts, and linkpaths. Each of these three arrays contains an array with the corresponding parents' names.
$loose_ends = 0;
$ending_count = 0;

date_default_timezone_set('America/Los_Angeles'); // Why the heck is this required just to do a preg_match?!?

// DEBUG LINE TEMPLATE: if($debug) echo "<br>\n";

//////////////
// THE CODE //
//////////////
$html = scrape($inkle_game_url);
$game_data = extract_game_data($html);
$title = extract_title($game_data);
$stitches = extract_stitches_section($game_data);
make_story_array($stitches);
//dbug($story_array, 'THIS IS IT, BABY:', '', 0);
make_parents_array();
make_cs_array($output_file);
make_graphviz();

//////////////////////
// HELPER FUNCTIONS //
//////////////////////

/* usage:

$a = array(array("id"=>10, "name"=>"joe"), array("id"=>11, "name"=>"bob"));

$ids = array_pluck("id", $a);        // == array(10,11)
$names = array_pluck("name", $a);    // == array("joe", "bob")

 //works on non-keyed arrays also:

$a = array(array(3,4), array(5,6));
$col2 = array_pluck(1,$a);            // == array(4,6) (grab 2nd column of data) */

function array_pluck($key, $array)
 {
     if (is_array($key) || !is_array($array)) return array();
     $funct = create_function('$e', 'return is_array($e) && array_key_exists("'.$key.'",$e) ? $e["'. $key .'"] : null;');
     return array_map($funct, $array);
 }

/*
FUNCTION: parseCurlyBrace($str)
 
$str = 'The {quick} brown fox {jumps {over the} lazy} dog';
$result = parseCurlyBrace($str);
echo '<pre>' . print_r($result,true) . '</pre>';

Array
(
    [0] => quick
    [1] => over the
    [2] => jumps {over the} lazy
)

If given a single nested block, Kanon says the last() array element should be the contents of the whole block (sans enclosing braces)
*/
function parseCurlyBrace($str) {

  $length = strlen($str);
  $stack  = array();
  $result = array();

  for($i=0; $i < $length; $i++) {

     if($str[$i] == '{') {
        $stack[] = $i;
     }

     if($str[$i] == '}') {
        $open = array_pop($stack);
        $result[] = substr($str,$open+1, $i-$open-1);
     }
  }

  return $result;
}

function searchForPageLabel($pageLabel, $array) {
/* Search arrays inside an array of arrays. Return the inner array that has its page_label element equal to $pageLabel. Currently used only to find the "start" stitch in $story_array. */
 foreach ($array as $key => $val) {
	if ($val['page_label'] === $pageLabel) {
           return $val;
       }
   }
   return null;
}

function find_story_element_by_name($name) {
/* Returns the index to $story_array */
global $story_array;

 foreach ($story_array as $key => $val) {
 	if ($val['name'] === $name) {
		return $key;
       }
   }
   return null;
}

function find_cs_story_element_by_name($name) {
/* Returns the index to $cs_array */
global $cs_array;

 foreach ($cs_array as $key => $val) {
 	if ($val['name'] === $name) {
		return $key;
       }
   }
   return null;
}

function init_output_file($output_file) {
	// Open input file for writing, erasing current contents
	if (!$fp = fopen($output_file, "w")) {
		 echo "Cannot open file";
		 exit;
	}
	return $fp;
}

function file_write($output_file_handle, $string) {
	if (fwrite($output_file_handle, $string) === FALSE) {
		echo "Cannot write to file";
		exit;
	}
	//dbug($string, '', '', 0);
}

function output_cs_label($label) {
	global $cs_text;

	$string = '*label '.$label."\n";
	$cs_text .= $string;
}

function output_cs_goto($label) {
	global $cs_text;

	$string = '*goto '.$label."\n";
	$cs_text .= $string;
}

function output_cs_text($text) {
	global $cs_text;

	$string = $text."\n";
	$cs_text .= $string;
}

function output_cs_choices($text) {
	global $cs_text;

	$string = $text."\n";
	$cs_text .= $string;
}

function output_cs_newline() {
	global $cs_text;
	
	$string = "\n";
	$cs_text .= $string;
}

function process_story_element($elt_name, $level) {
	global $story_array, $cs_array, $ending_count;

	$this_elt = find_story_element_by_name($elt_name);
	//dbug($elt_name."(Level: $level)", 'Processing');
	
	// Skip this element if already processed it. (Redundant. Done by process_divert() and process_choices() already.)
	if($story_array[$this_elt]['processed'] == true) {
		die('process_story_element() received an element already processed ('.$elt_name.'). Should never happen.');
	}

	// Only give a label if it will be used. Makes the choicescript look nicer, but loses the labels the Inkle code has. 
	$num_parents = num_parents($elt_name);
	if($num_parents['linkpaths'] > 0 || $num_parents['all_parents'] > 1) { // If any linkpaths in, give label. If 2 or more of any kind of parent, give label. Only time don't is if there's only one divert parent (and no linkpath parents).
		output_cs_label($elt_name);
	} else output_cs_newline(); // If only one divert parent and no linkpath parents, it's just the next paragraph.
	
	// Output text of the stitch 
	output_cs_text($story_array[$this_elt]['text']);

	// Add this stitch to $cs_array
	$cs_array[] = array(
		'name' => $elt_name,
		'level' => $level,										// Level in tree. (0=root, 1=next level down, etc) Removed. In $cs_array now.
		'page_label' => $story_array[$this_elt]['page_label'],
		//'page_num' =>
		'text' => $story_array[$this_elt]['text'], 				// Block's text
		'next_type' => $story_array[$this_elt]['next_type'],	// 'divert' or 'linkPath' (i.e. tells if the block ends with a goto or choices)
		'next_data' => $story_array[$this_elt]['next_data'],	// If next_type is 'divert' this will be a string containing the name of the block to goto.
																// 		If it's a 'linkPath', this will be an array of the choices
		'parents' => $parents_array[$this_elt]					// Array of names of parents. Might come in handy to know the parents for backtracing analysis at some point.
		);
	
	// Set the processed flag to true. This will prevent loops from creating dupes.
	$story_array[$this_elt]['processed'] = true;

											//dbug("process_story_element - 	Processing $elt_name(ID: $this_elt) - Setting it processed",0);

	// Process children (This is recursive)
	++$level;
	if($story_array[$this_elt]['next_type'] == 'divert') {
		process_divert($story_array[$this_elt]['next_data'], $level);
	} else if($story_array[$this_elt]['next_type'] == 'linkPath') {
		process_choices($story_array[$this_elt]['next_data'], $level);
	} else { // end of one branch reached. (Should this be *ending or *finish?)
		output_cs_text("*comment ENDING #".++$ending_count."\n*finish\n");
	}
}

function process_choices($choices, $level) {
	global $loose_ends;

	$string = "*choice\n";
	$num_choices = count($choices);
	if($num_choices > 1) { // More than one choice
		foreach($choices as $choice) {
			$string .= "\t#{$choice['choice_text']}\n";
			$linkpath = $choice['linkpath'];
			if($linkpath == '') $string .= "\t\t*comment LOOSE END #".++$loose_ends."\n\t\t*finish\n";
			else {
				$string .= "\t\t*goto $linkpath\n";
				if(!already_touched($linkpath)) proc_list_add($linkpath, $level); // Add these choices to the end of proc list to make output file put top of the "tree" first
			}
		}
	} else { // Only one choice. This code is identical to the above except put at front of $proc_list. Can it be made more elegant?
		$choice = current($choices);
		$string .= "\t#{$choice['choice_text']}\n";
		$linkpath = $choice['linkpath'];
//dbug($linkpath, 'process_choices:', '', 2);	
//dbug((already_touched($linkpath) ? 'true' : 'false'), 'Already touched:');
		if($linkpath == '') $string .= "\t\t*comment LOOSE END #".++$loose_ends."\n\t\t*finish\n";
		else {
			$string .= "\t\t*goto $linkpath\n";
			if(!already_touched($linkpath)) proc_list_add($linkpath, $level, 'front'); // If there's only one choice, put at beginning of proc list cuz often a continuation of linear text
		}
	}
	output_cs_choices($string);
}

function already_touched($name) {
/* 'Touched' means either already processed or in $proc_list. */
	global $proc_list, $story_array;
	$this_elt = find_story_element_by_name($name);
//dbug("already_touched($name) - Found this_elt: $this_elt - processed: ".(($story_array[$this_elt]['processed'])?'true':'false')." - in_array: ".((in_array($name, $proc_list, true))?'true':'false'));
	if($story_array[$this_elt]['processed'] == true || in_array($name, $proc_list, true)) return true;
	else return false;
}

function proc_list_add($linkpath, $level, $loc = 'back') {
/* Add $linkpath to $proc_list. $level is level in story tree. If $loc is 'front' put at beginning of array, else end. */
	//static $count = 0; //For debugging, remove.
	global $proc_list;

	//if($count++ > 80) exit; // Limit things just for testing. Remove this when ready for prime time.
	
	// Dupe check (Redundant. Done by process_divert() and process_choices() already.)
	$this_elt = find_story_element_by_name($linkpath);
	if($this_elt['processed'] == true) {
		die('Attempt to add dupe ('.$linkpath.') into $proc_list. Should never happen.');
	}
	
	// Make proc list elt
	$proc_list_elt = array('name' => $linkpath, 'level' => $level);
	if($loc != 'back') array_unshift($proc_list, $proc_list_elt);
	else $proc_list[] = $proc_list_elt; 
}

function process_divert($name, $level) {
/* If this link has already been processed or is in $proc_list, goto it. Otherwise, put it in $proc_list at the front, so it will be processed next. Nothing need be put in $cs_text in this second case because it's just the next paragraph. (Ikle labels these. Choicescript looks better without it. */
	if(already_touched($name)) {
		output_cs_goto($name);
		return;
	}
	/* Put this in process_story_element() instead
	if(num_parents($name) == 1) {
		output_cs_newline(); // If only one parent, assume it's the next paragraph, so put a newline between them. (*label already put by other function)
	} */
	
	proc_list_add($name, $level, 'front'); // Diverts go at beginning of proc list because often they're just the next paragraph on the same page
}

function num_parents($name) {
	global $parents_array;

	return array(
		'all_parents' => count($parents_array[$name]['all_parents']),
		'diverts' => count($parents_array[$name]['diverts']),
		'linkpaths' => count($parents_array[$name]['linkpaths'])
	);
}

function search_choices($choices, $skey, $sval) {
// If an element with key $skey with value of $sval is in array $choices, return true, else return false.
	$count = 0;
	$ret_array = array();
	foreach($choices as $key => $val) {
//echo "Searching for $sval in ($key same as $skey) $val[linkpath] same as (".$val[$skey].")\n";	

		if($val[$skey] == $sval) return true;
	}
	return false;
}

function sanitize_str($str) {
// Remove whitespace and then beginning and ending double-quotes from $str. Plus \n.
	//return trim(trim(trim(trim($str), '"'), '\n')); Caused unexplainable things to happen. Spaces before, after or both. Apos's to transmute to 2etm. Trailing 'n' to disappear. Oh, must be the \n. trim sees it as 2 things to trim.
	//dbug($str);
	//dbug(trim(trim(trim(trim($str), '"'), '\n')));
	return trim(trim($str), '"');
}

function scrape($url) {
	// create a new cURL resource
	$ch = curl_init();

	// set URL and other appropriate options
	curl_setopt($ch, CURLOPT_URL, $url);					// Set the url to get
	curl_setopt($ch, CURLOPT_HEADER, 0);				// Don't get the header section
	curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);			// Don't pass url to browser but return it instead

	// grab URL and put it in $html
	$html = curl_exec($ch);

	// close cURL resource, and free up system resources
	curl_close($ch);
	dbug("Scraped story from original website (".strlen($html)." characters).");
	
	return $html;
}

function extract_game_data($html) {
	preg_match('~^.*var storyData = \{\s+(.+)// Start from beginning\s+StoryModel\.importStory\(storyData\.title, storyData\.data\);\s+var initialStitches = Player.getSavedGame\(\);~s', $html, $ret);

	//dbug($ret[1], 'game data:');
	
	return $ret[1];
}

function dbug($var, $pre = '', $post = '', $size = 1) {
	global $debug;

	if(!$debug) return;
	
	if(is_array($var)) {
		$output = $pre.' '.print_r($var, true).' '.$post;
		$output = '<PRE>'.htmlspecialchars($output).'</PRE>';
	} else {
		$output = nl2br(htmlspecialchars($pre.' '.$var.' '.$post)).'<BR>';
	}
	
	if($size == 0) {
		$output = "$output\n";
	} else if($size == 1) {
		$output = "<B>$output</B>\n";
	} else if($size == 2) {
		$output = "<H2>$output</H2>\n";
	}
	echo $output;
}

function extract_title($game_data) {
	preg_match('/title: "([^"]+)",/', $game_data, $match);
	dbug($match[1], 'Title of game:');
	return $match[1];
}

function extract_stitches_section($game_data) {
	preg_match('/"stitches": \{(.+)/s', $game_data, $everything_after_stitches_tag);
	$ret = end(parseCurlyBrace($everything_after_stitches_tag[1]));

	//dbug($ret, 'The stitches and just the stitches:');
	
	return $ret;
	
}

function make_story_array($stitches) {
	global $story_array;

	// Make array of stitches
	$regex = '/"([^"]+)": \{\s+"content": \[(.*?\])\s+}/s';
	$num_stitches = preg_match_all($regex, $stitches, $st_array, PREG_SET_ORDER);
	/* At this point, $st_array is an array of all the stitches. Each member of the array is another array with:
		[0]: The stitch's name
		[1]: Everything else (text, then divert, linkpaths, etc) including the closing (but not opening) square bracket for future regex use */
		
	dbug($num_stitches, 'Number of stitches found:');
//	dbug($st_array, 'Array of all stitches only name extracted:');

	// Go through array of stitches extracting story data from each stitch
	foreach($st_array as $stitch) {
		// Get the name of the stitch
		$stitch_name = $stitch[1];
		
		// Get the text of the stitch
		preg_match('/"((?:[^"]|\\")+)",\s+\{\s+("(divert|linkPath)": [^]]+)/s', $stitch[2], $contents);
		$stitch_text = remove_inkle_weirdness($contents[1]);
		
		// Get the text if the above preg_match() failed. (There's neither a divert nor linkPath. Should re-write the regex?)
		if($stitch_text == '') {
			preg_match('/"((?:[^"]|\\")+)"\s+\]/s', $stitch[2], $story_text_array);
			$stitch_text = $story_text_array[1];
		}

		// Set $next_data. Will be a string with the next stitch's name in it for diverts and an array of linkpaths for linkpaths.
		//
		if(strcmp($contents[3], 'divert') == 0) {
			// If it's a divert block, parse it as such. $next_block[1]: where to divert (i.e. goto) to.
			preg_match('/"divert": "([^\s]+)"/', $contents[2], $next_block);
			if(strcmp($next_block[1], 'null')!=0) {$next_linkPath = $next_block[1]; }
			else {$next_linkPath = '';}
			$next_data = $next_linkPath;
		} else if(strcmp($contents[3], 'linkPath') == 0) {
			// If it's a linkPath block, parse it as such. $next_block will contain an array of all the choices. Each array elt will be another array with an element for each of the choice's components. For example, $next_block[0], the first choice, will have the following format: $next_block[0][1] is the linkpath (i.e. the label to goto), $next_block[0][2] is the choice's text, $next_block[0][3] is the notIfConditions, and $next_block[0][4] is the ifConditions. Code that uses this array will need to be aware that these may be in double quotes and have to remove them.
			$linkpath_block = $contents[2];
			$num_choices = preg_match_all('~"linkPath": ("((?:[^"]|\\\")+)"|null),\s+"option": ("(?:(?:[^"]|\\\")+)"|null),\s+"notIfConditions": ("(?:(?:[^"]|\\\")+)"|null),\s+"ifConditions": ("(?:(?:[^"]|\\\")+)"|null)\s+\}~s', $linkpath_block, $next_block, PREG_SET_ORDER);

			$next_data = array();
			foreach($next_block as $value) {
				if(strcmp($value[2], 'null') != 0) $next_linkPath = sanitize_str($value[2]); else $next_linkPath = '';
				if(strcmp($value[3], 'null') != 0) $next_option = stripslashes(sanitize_str(remove_inkle_weirdness($value[3]))); else $next_option = '';
				if(strcmp($value[4], 'null') != 0) $next_notIfConditions = sanitize_str($value[4]); else $next_notIfConditions = '';
				if(strcmp($value[5], 'null') != 0) $next_ifConditions = sanitize_str($value[5]); else $next_ifConditions = '';
				$next_data[] = array(
					'linkpath' => $next_linkPath,
					'choice_text' => $next_option,
					'notIfConds' => $next_notIfConditions,
					'ifConds' => $next_ifConditions
				);
			}
		} else { // No divert or linkPath, so assume end of story
			$next_data = '';
		}
		// Get page_label if it exists in this block
		preg_match('/\{\s+"pageLabel": "((?:[^"]|\\\")+)"\s+\}/s', $stitch[2], $page_label_array);
		$page_label = $page_label_array[1];
		
		/* Check if there are other things besides divert, linkPath, and pageLabel. (Do we need to also get pageNum?)
			At this point, this just notifies user but doesn't process. Found so far:
				"pageNum": 1
				"flagName": "broken window"
					Inline code to check flags: {day1 paid more: since he was such a chump, making you foot more of the bill.|.}
				"flagName": "jake_money+74"
					Inline code to show: ${number:shawn_money} (In other places, brackets used. $ seems optional or maybe has meaning. 
																number can also be 'value' to print it in either text or as a number)*/
//		dbug($stitch[2], "Checking for other things in:");
//		dbug(parseCurlyBrace($stitch[2]));
		foreach(parseCurlyBrace($stitch[2]) as $stitch_elt) {
			preg_match('/"((?:[^"]|\\\")+)"/', $stitch_elt, $match); // Get first "...". Assume it's divert, linkPath, etc.
			if($match[1] != 'divert' && $match[1] != 'linkPath' && $match[1] != 'pageLabel' && $match[1] != 'pageNum' && $match[1] != 'flagName') {
				dbug($match, 'A novel idea:');
			}
		}

		// Add new member to $story_array
		$story_array[] = array(
		'name' => $stitch_name,				 	// Name of the block (aka stitch). 
		'page_label' => $page_label,
		//'page_num' =>
		'content' => $stitch[0], 				// Just here for reference. Contains the unprocessed block. Can remove after debugging.
		'text' => stripslashes($stitch_text), 	// Block's text
		'next_type' => $contents[3],			// 'divert' or 'linkPath' (i.e. tells if the block ends with a goto or choices) Null if end of story.
		'next_data' => $next_data,				// If next_type is 'divert' this will be a string containing the name of the block to goto.
												// 		If it's a 'linkPath', this will be an array of the choices. Null if end of story.
		//'parents' => array(),					// Array of names of parents. Might come in handy to know the parents for backtracing analysis at some point. Removed. In $cs_array now.
		//'level' = 0,							// Level in tree. (0=root, 1=next level down, etc) Removed. In $cs_array now.
		'processed' => false					// To avoid dupes and loops, set this flag after processing this member
		);
	} // end of big foreach loop
} // end of function get_stitches

function remove_inkle_weirdness($str) {
/* It seems the raw javascript story data from Inkle has weird things added. This will remove the known ones. Examples:
		"option": "\nHmm... maybe it's a good idea this time?\n", enclosing \n's
		"option": "\nWring Shawn's scrawny little neck\n",
		" Place: Wisconsin, USA<BR>", (Leading space and trailing <BR>)
*/
	// Remove certain stuff
	$search = array('\n', '<BR>'); // Things to remove outright
	$ret = str_replace($search, '', trim($str));
	
	// Replace stuff
	$ret = str_replace('’', "'", trim($ret));
	$ret = str_replace('${', "[", trim($ret));
	$ret = str_replace('{', "[", trim($ret));
	$ret = str_replace('}', "]", trim($ret));
	return $ret;
}

function make_cs_array($output_file = '') {
/* Processes $story_array, simultaneously generating choicescript and creating $cs_array, an array containing the story data in order with extra data. If $output_file is empty, output to screen instead. If it's 'download', initiate download. */

global $story_array, $cs_array, $cs_text, $proc_list, $loose_ends, $ending_count;

// Determine output type
if($output_file == '') $output_type = 'screen';
else if($output_file == 'download') $output_type = 'download';
else $output_type = 'file';

// Get start of story
$story_start = searchForPageLabel('Start', $story_array);

// Walk the tree of the story, making choicescript ($cs_text) as we go, as well as $cs_array
process_story_element($story_start['name'], 0); // This will put something in $proc_list. The 0 is the stitch's level. (Stitch and story element are synonyms.)

// Iterate through $proc_list
while(!empty($proc_list)) {
	dbug("PROC_LIST(".count($proc_list)."):".implode(', ', array_pluck('name', $proc_list)),'','',1);
	//dbug($proc_list, 'Proc List:'); dbug(count($proc_list), 'Size of proc list:');
	$next_stitch = array_shift($proc_list);
	process_story_element($next_stitch['name'], $next_stitch['level']); // Process first member in $proc_list and remove it
}

// Success! $cs_text and $cs_array created. Output $cs_text.
dbug('Success!','','',2);
dbug('Outputting to '.$output_type);
dbug($loose_ends,'Loose ends:');
dbug($ending_count,'Number of endings:');
if($output_type == 'file') {
	$output_file_handle = init_output_file($output_file);
	file_write($output_file_handle, $cs_text);
	fclose($output_file_handle);
	dbug($output_file, 'Choicescript code in:');
} else if($output_type == 'download') {
	header("Content-Disposition: attachment; filename=\"" . basename($output_file) . "\"");
	//header("Content-Type: application/force-download"); If you wish to force a file to be downloaded and saved, instead of being rendered, remember that there is no such MIME type as "application/force-download". The correct type to use in this situation is "application/octet-stream", and using anything else is merely relying on the fact that clients are supposed to ignore unrecognised MIME types and use "application/octet-stream" instead (reference: Sections 4.1.4 and 4.5.1 of RFC 2046). Also according IANA there is no registered application/force-download type.
	header("Content-Type: application/octet-stream");
	header("Content-Length: " . filesize($output_file));
	header("Connection: close");
	exit;
} else {
	echo $cs_text;
}
}

function make_parents_array() {
	global $story_array, $parents_array;
	
	foreach($story_array as $stitch) {
		if($stitch['next_type'] == 'divert') {
			$parents_array[$stitch['next_data']]['all_parents'][] = $stitch['name'];
			$parents_array[$stitch['next_data']]['diverts'][] = $stitch['name'];
		} else if($stitch['next_type'] == 'linkPath') { 
			$choices = $stitch['next_data'];
			foreach($choices as $choice) {
				if($choice['linkpath'] != '') {
					$parents_array[$choice['linkpath']]['all_parents'][] = $stitch['name'];
					$parents_array[$choice['linkpath']]['linkpaths'][] = $stitch['name'];
				}
			}
		}  else {
			// Neither divert, nor linkPath. Probably '' for an end of story.
			dbug($stitch, 'Found a stitch with neither divert, nor linkPath in make_parents_array(). Probably an end of story.');
		}
	}
}

function make_graphviz() {
	global $cs_array;

	$output = '';
	$label_array = array();
	$edge_array = array();

	$output .= "digraph inkle2cs {\n";
	
	foreach($cs_array as $member) {
		$stitch_text = addslashes(substr($member['text'], 0, 122).(strlen($member['text']) > 122 ? '...' : ''));
		if(strlen($stitch_text) > 50) $stitch_text=substr_replace($stitch_text, '\n', 40, 0);
		if(strlen($stitch_text) > 90) $stitch_text=substr_replace($stitch_text, '\n', 80, 0);
		// if(!in_array($label_array, $member['name']."[label=\"$stitch_text\"]")) $label_array[] = $member['name']."[label=\"$stitch_text\"]";
		$label_array[] = "\t".$member['name']."[label=\"$stitch_text\"]";
		
		if($member['next_type'] == 'divert') {
			// It's a divert, so make an edge (no label)
			if($member['next_data'] != '') {
				//$output .= $member['next_data']."[label=\"$child_text\"]";
				 $edge_array[] = "\t{$member['name']} -> {$member['next_data']}";
			}
		} else if($member['next_type'] == 'linkPath') {
			// It's linkPaths, so make edges with the choice text as label
			foreach($member['next_data'] as $linkpath) {
				$choice_text = addslashes(substr($linkpath['choice_text'], 0, 22).(strlen($linkpath['choice_text']) > 22 ? '...' : ''));
				if($linkpath['linkpath'] != '') {
					$edge_array[] = "\t{$member['name']} -> {$linkpath['linkpath']}[label=\"$choice_text\",weight=\"$choice_text\"]";
				}
			}
		} else {
			// Neither divert, nor linkPath. Probably '' for an end of story.
			dbug($member, 'Found a stitch with neither divert, nor linkPath in make_graphviz(). Probably an end of story.');
		}
	}
	$output .= implode(";\n", $label_array);
	$output .= implode(";\n", $edge_array);
	$output .= '}';
	echo '<pre>'.$output.'</pre>';
}
		
?>