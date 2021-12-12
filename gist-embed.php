<?php if (!defined('PmWiki')) exit();
/** \pastebin-embed.php
  * \Copyright 2017-2021 Said Achmiz
  * \Licensed under the MIT License
  * \brief Embed Gists in a wikipage.
  */

$RecipeInfo['GistEmbed']['Version'] = '2021-12-11';

## (:gist-embed:)
Markup('gist-embed', '<fulltext', '/\(:gist-embed\s+(.+?)\s*:\)/', 'GistEmbed');

SDV($GistEmbedHighlightStyle, "background-color: yellow;");

function GistEmbed($m) {
	static $id = 1;
	
	## Parse arguments to the markup.
	$parsed = ParseArgs($m[1]);

	## These are the “bare” arguments (ones which don’t require a key, just value(s)).
	$args = $parsed[''];
	$gist_id = $args[0];
	$noJS = in_array('no-js', $args);
	$noFooter = in_array('nofooter', $args);
	$noLineNumbers = in_array('nolinenums', $args);
	$raw = in_array('raw', $args);
	$noPre = in_array('no-pre', $args);

	## Check whether specific files are being specified.
	$files = array();
	if ($args[1] && !in_array($args[1], array('no-js', 'nofooter', 'nolinenums', 'raw', 'no-pre')))
		$files = explode(',',$args[1]);
	
	## Convert the comma-delimited line ranges to an array containing each line to be
	## included as values.
	## Note that the line numbers will be zero-indexed (for use with raw text, etc.).
	$line_ranges = $parsed['lines'] ? explode(',', $parsed['lines']) : array();
	$line_numbers = array();
	$to_end_from = -1;
	foreach ($line_ranges as $key => $line_range) {
		if (preg_match("/([0-9]+)[-–]([0-9]+)/", $line_range, $m)) {
			$line_numbers = array_merge($line_numbers, range(--$m[1],--$m[2]));
		} else if (preg_match("/([0-9]+)[-–]$/", $line_range, $m)) {
			$line_numbers[] = $to_end_from = --$m[1];
		} else {
			$line_numbers[] = --$line_range;
		}
	}
	
	## Same thing, but for highlighted line ranges.
	$hl_line_ranges = $parsed['hl'] ? explode(',', $parsed['hl']) : array();
	$hl_line_numbers = array();
	$hl_to_end_from = -1;
	foreach ($hl_line_ranges as $key => $hl_line_range) {
		if (preg_match("/([0-9]+)[-–]([0-9]+)/", $hl_line_range, $m)) {
			$hl_line_numbers = array_merge($hl_line_numbers, range(--$m[1],--$m[2]));
		} else if (preg_match("/([0-9]+)[-–]$/", $hl_line_range, $m)) {
			$hl_line_numbers[] = $hl_to_end_from = --$m[1];
		} else {
			$hl_line_numbers[] = --$hl_line_range;
		}
	}
	
	$embed_js_url = "https://gist.github.com/$gist_id.js";
	$embed_raw_url = "https://gist.github.com/$gist_id/raw/";
	$embed_json_url = "https://gist.github.com/$gist_id.json";
	
	$out = "<span class='gist-embed-error'>Unknown error.</span>";
	
	if ($raw) {
		## If no filenames have been specified, we'll have to retrieve the file list from
		## the server; otherwise, we'll have no idea what files to request, and just 
		## retrieving the ‘raw’ URL for a multi-file gist (with no file specified) gets
		## the first file only...
		if (empty($files)) {
			$full_gist_data = json_decode(file_get_contents($embed_json_url),true);
			$files = $full_gist_data['files'];
		}
		
		## The raw text of each file of a multi-file gist must be retrieved individually.
		$out = array();
		foreach ($files as $filename) {
			$raw_text = file_get_contents($embed_raw_url.$filename);
			if (!$raw_text) return Keep("<span class='gist-embed-error'>Could not retrieve gist!</span>");
			
			$raw_lines = explode("\n", $raw_text);
			## Convert HTML entities.
			if (!$noPre) {
				foreach ($raw_lines as $line)
					$line = PVSE($line);
			}
			## Highlighting only works if no-pre is NOT enabled AND if we’re displaying a 
			## single file only.
			if (   !empty($hl_line_numbers) 
				&& !$noPre 
				&& count($files) == 1) {
				if ($hl_to_end_from >= 0)
					$hl_line_numbers = array_merge($hl_line_numbers, range($hl_to_end_from, count($raw_lines) - 1));
				foreach ($hl_line_numbers as $l) {
					$raw_lines[$l] = "<span class='gist-embed-highlighted-line'>" . rtrim($raw_lines[$l]) . "</span>";
				}
			}
			## Specifying line numbers only works if we’re displaying a single file only.
			if (  !empty($line_numbers) 
				&& count($files) == 1) {
				if ($to_end_from >= 0)
					$line_numbers = array_merge($line_numbers, range($to_end_from, count($raw_lines) - 1));
				$raw_lines = array_intersect_key($raw_lines, array_flip($line_numbers));
			}
			$raw_text = implode("\n", $raw_lines);
			
			## The ‘no-pre’ option means we shouldn’t wrap the text in a <pre> tag.
			$out[] = $noPre ? $raw_text : Keep("<pre class='escaped gistRaw' id='gistEmbed_$id_$filename'>\n" . $raw_text . "\n</pre>\n");
		}
		$out = implode($noPre ? "\n\n" : "", $out);
	} else if ($noJS) {
		include_once('simplehtmldom/simple_html_dom.php');
	
		$json_content = json_decode(file_get_contents($embed_json_url),true);
				
		## The style sheet.
		global $HTMLHeaderFmt;
		$HTMLHeaderFmt[] = "<link rel='stylesheet' type='text/css' href='" . $json_content['stylesheet'] . "' />\n";
		
		## The HTML.
		$content_html = str_get_html(stripcslashes($json_content['div']));
		$content = $content_html->find("div.gist", 0);
		$content->id = "gistEmbed_$id";
		
		## If specific files are specified, we simply delete the div.gist-file containers
		## that contain files we don’t want.
		if (!empty($files)) {
			$file_ids = preg_replace("/\./", "-", $files);
			$gist_file_blocks = $content_html->find("div.gist-file");
			foreach ($gist_file_blocks as $gist_file_block) {
				if (!in_array(substr($gist_file_block->find("div.file", 0)->id, 5), $file_ids))
					$gist_file_block->outertext = '';
			}
		}
		
		## Specifying line numbers only works if we’re displaying a single file only.
		$displayed_gist_files = array_filter($content_html->find("div.gist-file"), function ($d) { return $d->outertext; });
		if (  !empty($line_numbers) 
			&& count($displayed_gist_files) == 1) {
			$lines = reset($displayed_gist_files)->find(".js-file-line-container tr");
			if ($to_end_from >= 0)
				$line_numbers = array_merge($line_numbers, range($to_end_from, count($lines) - 1));
			foreach ($lines as $l) {
				$line_num =$l->childNodes(0)->getAttribute('data-line-number');
				if (!in_array(--$line_num, $line_numbers))
					$l->outertext = '';
			}
		}
		
		## Highlighting specific line numbers only works if we’re displaying 
		## a single file only.
		if (  !empty($hl_line_numbers) 
			&& count($displayed_gist_files) == 1) {
			$lines = reset($displayed_gist_files)->find(".js-file-line-container tr");
			if ($hl_to_end_from >= 0)
				$hl_line_numbers = array_merge($hl_line_numbers, range($hl_to_end_from, count($lines) - 1));
			foreach ($lines as $i => $line) {
				if (in_array($i, $hl_line_numbers)) {
					$line->children(1)->class .= " gist-embed-highlighted-line";
				}
			}
		}
		
		$out = Keep($content);
	} else {
		$out = Keep("<script id='gistEmbedScript_$id' src='$embed_js_url'></script>");
		$out .= Keep("
<script>
	document.querySelector('#gistEmbedScript_$id').parentElement.nextSibling.id = 'gistEmbed_$id';
</script>
		");
		
		## If specific files are specified, we’ll delete the div.gist-file containers 
		## that contain files we don’t want (this script will run right after the script
		## that adds the content in the first place).
		if (!empty($files)) {
			$files_js = preg_replace("/\./", "-", "[ '" . implode("', '", $files) . "' ]");
			$out .= Keep("
<script>{
	let files = $files_js;
	document.querySelector('#gistEmbed_$id').querySelectorAll('div.gist-file').forEach(function (gist_file_block) {
		if (files.indexOf(gist_file_block.querySelector('div.file').id.substring(5)) == -1)
			gist_file_block.parentElement.removeChild(gist_file_block);
	});	
}</script>
			");
		}
		
		## Specifying line numbers only works if we’re displaying a single file only.
		if (   !empty($line_numbers) 
			|| !empty($hl_line_numbers)) {
			$line_numbers_js = "[ " . implode(", " , $line_numbers) . " ]";
			$hl_line_numbers_js = "[ " . implode(", " , $hl_line_numbers) . " ]";
			$out .= Keep("
<script>{
	if (document.querySelector('#gistEmbed_$id').querySelectorAll('div.gist-file').length == 1) {
		let num_lines = document.querySelector('#gistEmbed_$id').querySelector('div.gist-file').querySelectorAll('.js-file-line-container tr').length;

		let line_numbers = $line_numbers_js;
		let to_end_from = $to_end_from;
		if (to_end_from >= 0)
			line_numbers = [...line_numbers, ...[...Array(num_lines - to_end_from)].map((_, i) => to_end_from + i)];

		let hl_line_numbers = $hl_line_numbers_js;
		let hl_to_end_from = $hl_to_end_from;
		if (hl_to_end_from >= 0)
			hl_line_numbers = [...hl_line_numbers, ...[...Array(num_lines - hl_to_end_from)].map((_, i) => hl_to_end_from + i)];

		document.querySelector('#gistEmbed_$id').querySelector('div.gist-file').querySelectorAll('.js-file-line-container tr').forEach(function (line, i) {
			// Highlight specified line ranges (if any have been specified via the hl= parameter).
			if (hl_line_numbers.indexOf(i) != -1)
				line.children[1].className += ' gist-embed-highlighted-line';

			// Filter specified line ranges (if any have been specified via the lines= parameter).
			if (line_numbers.length > 0 && line_numbers.indexOf(i) == -1)
				line.parentElement.removeChild(line);
		});
	}
}</script>
			");
		}
	}
	
	global $HTMLStylesFmt;
	if (!$raw && $noFooter) {
		$HTMLStylesFmt['gist-embed'][] = "#gistEmbed_$id .gist-meta { display: none; }\n";
		$HTMLStylesFmt['gist-embed'][] = "#gistEmbed_$id .gist-data { border-bottom: none; border-radius: 2px; }\n";
	}
	if (!$raw && $noLineNumbers) {
		$HTMLStylesFmt['gist-embed'][] = "#gistEmbed_$id td.js-line-number { display: none; }\n";
	}

	GistEmbedInjectStyles();
	
	$id++;
	return $out;
}

function GistEmbedInjectStyles() {
	static $ran_once = false;
	if (!$ran_once) {
		global $HTMLStylesFmt, $GistEmbedHighlightStyle;
		$styles = "
.gistRaw .gist-embed-highlighted-line { $GistEmbedHighlightStyle display: inline-block; width: calc(100% + 4px); padding-left: 4px; margin-left: -4px; }
.gist tr .gist-embed-highlighted-line { $GistEmbedHighlightStyle }
";
		$HTMLStylesFmt['gist-embed'][] = $styles;
	}
	$ran_once = true;
}
