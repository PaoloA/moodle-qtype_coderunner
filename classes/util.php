<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Utility routines for qtype_coderunner
 */

defined('MOODLE_INTERNAL') || die();



global $CFG;

use qtype_coderunner\constants;

class qtype_coderunner_util {
    /*
     * Load/initialise the specified UI JavaScipt plugin  for the given question.
     * A null plugin loads Ace.
     * $textareaid is the id of the textarea that the UI plugin is to manage.
     */
    public static function load_uiplugin_js($question, $textareaid, $loadAce=true) {
        global $CFG, $PAGE;

        if ($loadAce) {
            self::load_ace(); // Ace isn't an AMD module. Load all its modules.
        }
        $uiplugin = $question->uiplugin === null ? 'ace' : strtolower($question->uiplugin);
        if ($uiplugin !== '' && $uiplugin !== 'none') {
            $PAGE->requires->strings_for_js(constants::ui_plugin_keys(), 'qtype_coderunner');
            $params = array($uiplugin, $textareaid, $question->templateparams); // Params to plugin's init function.
            if ($uiplugin === 'ace') {
                if (!$loadAce) {  // Shouldn't happen, but better safe than sorry
                    self::load_ace();
                }
                if (empty($question->acelang)) {
                    $lang = $question->language;
                } else {
                    // If an ace language is given, it might be a multilanguage
                    // question. Set the language to the specified default, if supplied,
                    // or the first language otherwise (which also works for
                    // non-multilanguage questions).
                    list($langs, $default) = self::extract_languages($question->acelang);
                    if (!empty($default)) {
                        $lang = $default;
                    } else {
                        $lang = $langs[0];
                    }
                }
                $lang = ucwords($lang);
                $params[] = $lang;
            }
            $PAGE->requires->js_call_amd('qtype_coderunner/userinterfacewrapper', 'newUiWrapper', $params);
        }
    }


    // Load the ace scripts.
    public static function load_ace() {
        global $PAGE;
        $plugindirrel = '/question/type/coderunner';
        $PAGE->requires->js($plugindirrel . '/ace/ace.js');
        $PAGE->requires->js($plugindirrel . '/ace/ext-language_tools.js');
        $PAGE->requires->js($plugindirrel . '/ace/ext-modelist.js');
    }


    // A utility method used for iterating over multibyte (utf-8) strings
    // in php. Taken from https://stackoverflow.com/questions/3666306/how-to-iterate-utf-8-string-in-php
    // We can't simply use mb_substr to extract the ith characters from a multibyte
    // string as it has to search from the start, resulting in
    // quadratic complexity for a simple char-by-char iteration.
    private static function next_char($string, &$pointer){
        if(!isset($string[$pointer])) return false;
        $char = ord($string[$pointer]);
        if($char < 128){
            return $string[$pointer++];
        }else{
            if($char < 224){
                $bytes = 2;
            }elseif($char < 240){
                $bytes = 3;
            }elseif($char < 248){
                $bytes = 4;
            }elseif($char == 252){
                $bytes = 5;
            }else{
                $bytes = 6;
            }
            $str =  substr($string, $pointer, $bytes);
            $pointer += $bytes;
            return $str;
        }
    }
    // Return a copy of $s with trailing blank lines removed and trailing white
    // space from each line removed. Also sanitised by replacing all control
    // chars except newlines with hex equivalents.
    // A newline terminator is added at the end unless the string to be
    // returned is otherwise empty.
    // Used e.g. by the equality grader subclass.
    // UTF-8 character handling is rudimentary - only standard ASCII
    // control characters, whitespace etc are processed.
    public static function clean(&$s) {
        $nls = '';     // Unused line breaks.
        $output = '';  // Output string.
        $spaces = '';  // Unused space characters.
        $pointer = 0;
        $c = self::next_char($s, $pointer);
        while ( $c !== false) {
            if ($c === ' ') {
                $spaces .= $c;
            } else if ($c === "\n") {
                $spaces = ''; // Discard spaces before a newline.
                $nls .= $c;
            } else {
                if ($c === "\r") {
                    $c = '\\r';
                } else if ($c === "\t") {
                    $c = '\\t';
                } else if ($c < " ") {
                    $c = '\\x' . sprintf("%02x", ord($c));
                }
                $output .= $nls . $spaces . $c;
                $spaces = '';
                $nls = '';
            }
            $c = self::next_char($s, $pointer);
        }
        if ($output !== '') {
            $output .= "\n";
        }
        return $output;
    }


    // Limit the length of the given string to MAX_STRING_LENGTH by
    // removing the centre of the string, inserting the substring
    // [... snip ... ] in its place.
    // FIXME: can mess up UTF-8 multibyte strings.
    public static function snip(&$s) {
        $snipinsert = ' ...snip... ';
        $len = strlen($s);
        if ($len > constants::MAX_STRING_LENGTH) {
            $lentoremove = $len - constants::MAX_STRING_LENGTH + strlen($snipinsert);
            $partlength = ($len - $lentoremove) / 2;
            $firstbit = substr($s, 0, $partlength);
            $lastbit = substr($s, $len - $partlength, $partlength);
            $s = $firstbit . $snipinsert . $lastbit;
        }
        return $s;
    }


    // Return a cleaned and snipped version of the string s (or null if s is null).
    public static function tidy($s) {
        if ($s === null) {
            return null;
        } else {
            $cleaneds = self::clean($s);
            return self::snip($cleaneds);
        }
    }


    // Sanitise given text with 's()' and wrap in a <pre> element.
    // TODO: expand tabs (which appear in Java traceback output).
    public static function format_cell($cell) {
        if (substr($cell, 0, 1) === "\n") {
            $cell = "\n" . $cell;  // Fix <pre> quirk that ignores leading \n
        }
        return '<pre class="tablecell">' . s($cell) . '</pre>';
    }


    // Clean the given html by wrapping it in <div> tags and parsing it with libxml
    // and outputing the (supposedly) cleaned up HTML.
    public static function clean_html($html) {
        libxml_use_internal_errors(true);
        $html = "<div>". $html . "</div>"; // Wrap it in a div (seems to help libxml).
        $doc = new DOMDocument;
        if ($doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            return $doc->saveHTML();
        } else {
            $message = "Errors in HTML\n<br />";
            foreach (libxml_get_errors() as $error) {
                $message .= "Line {$error->line} column {$error->line}: {$error->code}\n<br />";
            }
            libxml_clear_errors();
            $message .= "\n<br />" + $html;
            return $message;
        }
    }

    /**
     * Convert a given list of lines to an HTML <p> element.
     * @param type $lines
     */
    public static function make_html_para($lines) {
        if (count($lines) > 0) {
            $para = html_writer::start_tag('p');
            $para .= $lines[0];
            for ($i = 1; $i < count($lines); $i++) {
                $para .= html_writer::empty_tag('br') . $lines[$i];;
            }
            $para .= html_writer::end_tag('p');
        } else {
            $para = '';
        }
        return $para;
    }


    /**
     * Parse the ace-language field to obtain the list of languages to be
     * accepted and the default language to use.
     * @param string $acelangstring the contents of the 'Ace language' field
     *  in the authoring form
     * @return a 2-element array consisting of the list of languages and the
     *  default language (if given) or an empty string (otherwise).
     * If more than one language is specified as a default, i.e. has a trailing
     * '*' appended, the function returns FALSE.
     */
    public static function extract_languages($acelangstring) {
        $langs = preg_split('/ *, */', $acelangstring);
        $filteredlangs = array();
        $defaultlang = '';
        foreach ($langs as $lang) {
            $lang = trim($lang);
            if ($lang === '') {
                continue;
            }
            if ($lang[strlen($lang) - 1] === '*') {
                $lang = substr($lang, 0, strlen($lang) - 1);
                if ($defaultlang !== '') {
                    return false;
                } else {
                    $defaultlang = $lang;
                }
            }
            $filteredlangs[] = $lang;
        }
        return array($filteredlangs, $defaultlang);
    }

}