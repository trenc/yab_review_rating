<?php

$plugin['name'] = 'yab_review_rating';
$plugin['allow_html_help'] = 0;
$plugin['version'] = '0.1';
$plugin['author'] = 'Tommy Schmucker';
$plugin['author_uri'] = 'http://www.yablo.de/';
$plugin['description'] = 'A comment based rating system for articles.';
$plugin['order'] = '5';
$plugin['type'] = '1'; // public and admin

if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001);
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002);

$plugin['flags'] = '2';

// Plugin textpack
$plugin['textpack'] = <<< EOT
#@admin
#@language en-gb
yab_rating_label => Rating
#@language en-us
yab_rating_label => Rating
#@language de-de
yab_rating_label => Bewertung
#@language fr-fr
yab_rating_label => Évaluation
#@language es-es
yab_rating_label => Clasificación
#@language it-it
yab_rating_label => valutazione
#@language fi-fi
yab_rating_label => Luokitus
#@language nl-nl
yab_rating_label => Aanslag
#@language ru-ru
yab_rating_label => Pейтинг
EOT;


if (!defined('txpinterface'))
{
	@include_once('zem_tpl.php');
}

# --- BEGIN PLUGIN CODE ---
/**
 * yab_review_rating
 *
 * A Textpattern CMS plugin.
 * A comment based rating system for articles.
 *
 * @author Tommy Schmucker
 * @link   http://www.yablo.de/
 * @link   http://tommyschmucker.de/
 * @date   2013-12-24
 *
 * This plugin is released under the GNU General Public License Version 2 and above
 * Version 2: http://www.gnu.org/licenses/gpl-2.0.html
 * Version 3: http://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * Config function holder to avoid some globals
 * Can later be changed to receive config from database
 *
 * @param  string $name name of the config
 * @return string
 */
function yab_rr_config($name)
{
	$config = array(
		'min' => 0, // min value of the rating (0-255)
		'max' => 5  // max value of the rating (0-255)
	);

	return $config[$name];
}

// admin callbacks
if (@txpinterface == 'admin') {
	register_callback(
		'yab_rr_install',
		'plugin_lifecycle.yab_review_rating',
		'installed'
	);
	register_callback(
		'yab_rr_discuss_ui',
		'admin_side',
		'body_end'
	);
	register_callback(
		'yab_rr_discuss_save',
		'discuss',
		'discuss_save'
	);
}

// public callbacks
register_callback('yab_rr_save', 'comment.saved');
register_callback('yab_rr_check', 'comment.save');

/**
 * Save the rating
 * Adminside Textpattern callback function
 * Fired after discuss is saved
 *
 * @param  string $event Textpattern admin event
 @ @param  string $step  Textpattern admin step
 * @return boolean      true on success
 */
function yab_rr_discuss_save($event, $step)
{
	$discussid = doSlash(assert_int(ps('discussid')));
	$rating    = doSlash(intval(ps('yab_rr_rating')));
	
	$rs = safe_update(
		'txp_discuss',
		"yab_rr_rating = '$rating'",
		"discussid = '$discussid'"
	);

	if ($rs)
	{
		update_lastmod();
		return true;
	}
	return false;
}

/**
 * Show Rating Input field in discuss edit panel
 * Adminside Textpattern callback function
 * Fired at body_end in ui
 *
 * @return mixed string in Textpattern admin step 'discuss_edit' | false
 */
function yab_rr_discuss_ui()
{
	global $step;

	// be sure we are in discuss edit area
	if ($step != 'discuss_edit')
	{
		return false;
	}

	$discussid = gps('discussid');
	$rating    = safe_field('yab_rr_rating', 'txp_discuss', "discussid = $discussid");
	$label     = gTxt('yab_rating_label');
	$js        = <<<EOF
<script>
(function() {
	var yab_rr_js = '<p class="yab-edit-rating"><span class="edit-label"><label for="yab_rr_rating">$label</label></span><span class="edit-value"><input type="text" value="$rating" name="yab_rr_rating" size="32" id="yab_rr_rating"></span></p>';
	$('.edit-name', '#discuss_edit_form').after(yab_rr_js);
})();
</script>
EOF;

	echo $js;
}

/**
 * Textpattern tag
 * Display the rating
 * Can only be used in the comments form
 *
 * @param  array $atts Array of Textpattern tag attributes
 * @return string
 */
function yab_review_rating($atts)
{
	global $thiscomment;

	assert_comment();

	extract(
		lAtts(
			array(
				'char' => '' // type of input, if empty number is displayed
			), $atts
		)
	);

	// is preview or saved comment
	if (isset($thiscomment['yab_rr_rating']))
	{
		$rating = $thiscomment['yab_rr_rating'];
	}
	else
	{
		$rating = intval(ps('yab_rr_value'));
	}

	if ($char)
	{
		$chars = '';
		for ($i = 0; $i < $rating; $i++)
		{
			$chars .= $char;
		}
		return $chars;
	}

	return $rating;
}

/**
 * Check rating value and
 * Public callback function
 * Fired after comment is send
 *
 * @return void Die on invalid value
 */
function yab_rr_check()
{
	$min    = yab_rr_config('min');
	$max    = yab_rr_config('max');
	$rating = intval(ps('yab_rr_value'));

	if ($rating < $min or $rating > $max)
	{
		txp_die(
			'Unable to record the comment. No valid rating value.',
			'412 Precondition failed');
	}
}

/**
 * Save the rating after the comment is saved
 * Public callback function
 * Fired after comment is saved
 *
 * @param  string  $event   public Textpattern event
 * @param  string  $step    public Textpattern step
 * @param  array   $comment array of name-value pairs of saved comment
 * @return boolean true on success
 */
function yab_rr_save($event, $step, $comment)
{
	$id     = $comment['commentid'];
	$rating = doSlash(intval(ps('yab_rr_value')));

	$rs = safe_update(
		'txp_discuss',
		"yab_rr_rating = '$rating'",
		"discussid = '$id'"
	);

	if ($rs)
	{
		return true;
	}
	return false;
}

/**
 * Textpattern tag
 * Display the rating input in the comment form
 *
 * @param  array $atts Array of Textpattern tag attributes
 * @return string
 */
function yab_review_rating_input($atts)
{
	extract(
		lAtts(
			array(
				'type'    => 'text', // select, text, radio, number, range
				'html_id' => '', // HTML id to apply the item attribute value
				'class'   => 'yab-rr-review', // HTML class to apply the item attribute value
				'break'   => 'br', // br or empty
				'default' => '' // preselected rating value
			), $atts
		)
	);

	$selector_attributes = '';
	$min                 = yab_rr_config('min');
	$max                 = yab_rr_config('max');

	if (!$default)
	{
		$default = $min;
	}

	// show selected value on comment preview
	if (ps('preview'))
	{
		$default = intval(ps('yab_rr_value'));
	}

	$value_attribute = ' value="'.$default.'"';

	if ($class)
	{
		$selector_attributes .= ' class="'.$class.'"';
	}

	if ($html_id)
	{
		$selector_attributes .= ' id="'.$html_id.'"';
	}

	switch ($type)
	{
		case 'select':
			$options = '';
			for ($i = $min; $i <= $max; $i++)
			{
				$selected = '';
				if ($i == $default)
				{
					$selected = ' selected="selected"';
				}
				$options .= '<option value="'.$i.'"'.$selected.'>'.$i.'</option>';
			}
			$out = '<select name="yab_rr_value"'
				.$selector_attributes.'>'
				.$options
			.'</select>';
			break;
		case 'radio':
			$radios = array();
			for ($i = $min; $i <= $max; $i++)
			{
					$checked = '';
					if ($i == $default)
					{
						$checked = ' checked="checked"';
					}
					$radios[] = '<label for="yab-rr-'.$i.'">'.$i.'</label>'
						.'<input  name="yab_rr_value" type="'.$type.'"'
						.$checked
						.' value="'.$i.'"'
						.' id="yab-rr-'.$i.'"'
					.' />';
				}
			
			$out = doWrap($radios, '', $break);
			break;
		case 'text':
			$out = '<input name="yab_rr_value" type="'.$type.'"'
				.$selector_attributes
				.$value_attribute
			.' />';
			break;
		case 'number':
		case 'range':
			$out = '<input name="yab_rr_value"  type="'.$type.'"'
				.$selector_attributes
				.$value_attribute
				.' min="'.$min.'" max="'.$max.'"'
			.' />';
			break;
		default:
			$out = '';
			break;
	}

	return $out;
}

/**
 * Install the rating column inside txp_discuss
 * It is an unsigned tinyint type so 0-255 are valid values
 *
 * @return void
 */
function yab_rr_install()
{
	$columns = getRows('show columns from '.safe_pfx('txp_discuss'));
	foreach($columns as $column)
	{
		if ($column['Field'] == 'yab_rr_rating')
		{
			return;
		}
	}
	safe_query(
		"ALTER TABLE "
		.safe_pfx("txp_discuss")
		." ADD yab_rr_rating TINYINT UNSIGNED NOT NULL DEFAULT '0';"
	);
}

# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. yab_review_rating

p. A comment based rating system for articles.

p. *Version:* 0.1

h2. Table of contents

# "Plugin requirements":#help-section02
# "Configuration":#help-config03
# "Tags":#help-section05
# "Examples":#help-section09
# "License":#help-section10
# "Author contact":#help-section11

h2(#help-section02). Plugin requirements

p. yab_review_rating's  minimum requirements:

* Textpattern 4.x

h2(#help-config03). Configuration

Open the plugin code. the first function contains the configuration values. There is a min and a max values for the rating. Valid values are 0-255.

h2(#help-section05). Tags

h3. yab_review_rating

p. Place this in your comment form. It will show the rating of the current comment.
Can only be placed in a @comments@ form.

*char:* a valid string
Default: __no set__
If empty (default) the output will be the rating number. If a char (e.g. a asterisk @*@) is set the output will be the n-times repeated char, where n is the rating.

h3. yab_review_rating_input

p. The form element for the rating. Should be placed in the @comment_form@ form.

*type:* input type (text, select, radio, number, range)
Default: text
The type of the form element for the rating. Valid value are @text@, @select@, @radio@, @number@ and @range@.

*html_id:* HTML id name
Default: __not set__
The HTML id attribute applied to the element.

*class:* HTML class name
Default: __not set__
The HTML/CSS class attribute applied to the element.

*break:* breakpoint (br, __empty__)
Default: 'br'
Breakpoints für radio intputs. Can be empty or @br@.

*default:* integer
Default: __not set__
Preselected rating value (Could be any number between your min and max values).

h2(#help-section09). Examples

h3. Example 1

Example of @yab_review_rating_input@ in a @comment_form@ form.

bc.. <txp:comments_error wraptag="ul" break="li" />
	<div class="message">
		<p><label for="name">Name:</label><br /><txp:comment_name_input /></p>
		<p><label for="email">Mail (not required, not visible):</label><br />
			<txp:comment_email_input /></p>
		<p><label for="yab-rr-rating">Rating</label><br />
			<txp:yab_review_rating_input html_id="yab-rr-rating" type="select" default="3" /></p>
		<p><label for="message">Review:</label><br />
			<txp:comment_message_input /></p>
		<p class="submit"><txp:comments_help /><txp:comment_preview /><txp:comment_submit /></p>
</div>

p. Will produce a comment form for article reviews (e.g. with yab_shop) select dropdown menu and the preselected rating value 3.

h3. Example 2

Example of @yab_review_rating@ in a @comments@ form.

bc.. <h3 class="commenthead"><txp:comment_permlink>#</txp:comment_permlink> - <txp:comment_name /> wrote at <txp:comment_time />:</h3>
<span class="rating">Rating: <txp:yab_review_rating char="*" /></span>
<txp:comment_message />

p. Will produce a comment/review with the name, text and time of the comment and the rating with asterisks @*@.

h3. Example 3

Example of @yab_review_rating@ in a @comments@ form.

bc.. <h3 class="commenthead"><txp:comment_permlink>#</txp:comment_permlink> - <txp:comment_name /> wrote at <txp:comment_time />:</h3>
<span class="rating rating-value-<txp:yab_review_rating />">Rating:</span>
<txp:comment_message />

p. Will produce a the a comment/review with the name, text and time of the comment and the rating as HTML/CSS class..

h2(#help-section10). Licence

This plugin is released under the GNU General Public License Version 2 and above
* Version 2: "http://www.gnu.org/licenses/gpl-2.0.html":http://www.gnu.org/licenses/gpl-2.0.html
* Version 3: "http://www.gnu.org/licenses/gpl-3.0.html":http://www.gnu.org/licenses/gpl-3.0.html

h2(#help-section11). Author contact

* "Plugin on author's site":http://www.yablo.de/article/475/yab_review_rating-a-comment-based-rating-system-for-textpattern
* "Plugin on GitHub":https://github.com/trenc/yab_review_rating
# --- END PLUGIN HELP ---
-->
<?php
}
?>
