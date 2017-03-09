<?php

$plugin['name'] = 'yab_review_rating';
$plugin['allow_html_help'] = 0;
$plugin['version'] = '0.6';
$plugin['author'] = 'Tommy Schmucker';
$plugin['author_uri'] = 'http://www.yablo.de/';
$plugin['description'] = 'A comment based rating system for articles.';
$plugin['order'] = '5';
$plugin['type'] = '1';

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

if (class_exists('\Textpattern\Tag\Registry'))
{
	Txp::get('\Textpattern\Tag\Registry')
		->register('yab_review_rating')
		->register('yab_review_rating_input')
		->register('yab_review_rating_average');
}


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
 * Textpattern tag
 * Display the rating average for a given article
 *
 * @param  array $atts Array of Textpattern tag attributes
 * @return string
 */
function yab_review_rating_average($atts)
{
	global $thisarticle;

	extract(
		lAtts(
			array(
				'id'             => '', // article ids (comma separated) or empty in article context
				'exclude'        => null, // exclude ratings from calculations (e.g. 0)
				'only_visible'   => 1, // show all or only visible comments/reviews
				'default'        => 'not yet rated', // default text on articles without rating
				'decimals'       => 1, // precision of the calculation
				'separator'      => '.', // decimal separator
				'round_to_half'  => '' // round to first half integer up or down or not at all (up|down|empty)
			), $atts
		)
	);

	$exclude_rating = '';
	$average        = $default;

	if ($id)
	{
		$id = do_list($id);
		$id = join("','", doSlash($id));
	}
	else
	{
		assert_article();
		$id = $thisarticle['thisid'];
	}
	$parentid = "parentid IN ('$id')";

	$visible = '';
	if ($only_visible)
	{
		$visible = "AND visible = 1";
	}

	if ($exclude !== null)
	{
		$exclude_rating = do_list($exclude);
		$exclude_rating = join("','", doSlash($exclude_rating));
		$exclude_rating = "AND yab_rr_rating NOT IN ('$exclude_rating')";
	}

	$rs = safe_rows(
		'yab_rr_rating',
		'txp_discuss',
		"$parentid $visible $exclude_rating"
	);

	if ($rs)
	{
		$count   = sizeof($rs);
		$sum     = array_map('yab_rr_get_array_column', $rs);
		$sum     = array_sum($sum);
		$average = $sum / $count;

		if ($round_to_half)
		{
			if ($round_to_half == 'down')
			{
				$average = floor($average * 2) / 2;
			}
			else
			{
				$average = ceil($average * 2) / 2;
			}
		}

		$average = number_format($average, $decimals, $separator, '');
	}

	return $average;
}

/**
 * Get the rating column of the safe_rows() array
 * Is used as array_map function to build a array_sum array
 *
 * @param  array $element Array
 * @return string         yab_rr_rating Column from param array
 */
function yab_rr_get_array_column($element)
{
	return $element['yab_rr_rating'];
}

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
	var yab_rr_js = '<div class="txp-form-field edit-comment-yab-rating"><div class="txp-form-field-label"><label for="yab_rr_rating">$label</label></div><div class="txp-form-field-value"><input type="text" value="$rating" name="yab_rr_rating" size="32" id="yab_rr_rating"></div></div>';
	$('.edit-comment-name', '#discuss_edit_form').after(yab_rr_js);
})();
</script>
EOF;

	echo $js;
}

/**
 * Textpattern tag
 * Display the rating
 *
 * @param  array $atts Array of Textpattern tag attributes
 * @return string
 */
function yab_review_rating($atts)
{
	global $thiscomment;

	extract(
		lAtts(
			array(
				'id'  => '', // id of the comment
				'char' => '' // type of input, if empty number is displayed
			), $atts
		)
	);

	// commentid is given, serve before all othe
	if ($id)
	{
		$discussid = (int) $id;
		$rating = safe_field('yab_rr_rating', 'txp_discuss', "discussid = $discussid");
	}
	else
	{
		// normal comment list
		if (isset($thiscomment['yab_rr_rating']))
		{
			$rating = $thiscomment['yab_rr_rating'];
		}
		// recent comments
		elseif (isset($thiscomment['discussid']))
		{
			$discussid = $thiscomment['discussid'];
			$rating    = safe_field('yab_rr_rating', 'txp_discuss', "discussid = $discussid");
		}
		// comment preview
		else
		{
			$rating = intval(ps('yab_rr_value'));
		}
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
				'reverse' => 0, // reverse order for radio and select types
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
			$options = array();
			for ($i = $min; $i <= $max; $i++)
			{
				$selected = '';
				if ($i == $default)
				{
					$selected = ' selected="selected"';
				}
				$options[] = '<option value="'.$i.'"'.$selected.'>'.$i.'</option>';
			}
			if ($reverse)
			{
				$options = array_reverse($options);
			}
				$out = doWrap(
					$options,
					'select',
					'',
					'',
					'',
					' name="yab_rr_value"'.$selector_attributes
				);
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
			if ($reverse)
			{
				$radios = array_reverse($radios);
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

p. *Version:* 0.6

h2. Table of contents

# "Plugin requirements":#help-section02
# "Configuration":#help-config03
# "Tags":#help-section05
# "Examples":#help-section09
# "Changelog":#help-section10
# "License":#help-section11
# "Author contact":#help-section12

h2(#help-section02). Plugin requirements

p. yab_review_rating's  minimum requirements:

* Textpattern 4.x

h2(#help-config03). Configuration

Open the plugin code. the first function contains the configuration values. There is a min and a max values for the rating. Valid values are 0-255.

h2(#help-section05). Tags

h3. yab_review_rating

Place this in your comment form. It will show the rating of the current comment.
Can be used elsewhere. If not used in comment context as comments_form or recent_comments you have to fill the id attribute.

*id:* integer (comment id)
Default: __no set__
Show the rating of a comment with this ID. Useful in a non comment context.

*char:* a valid string
Default: __no set__
If empty (default) the output will be the rating number. If a char (e.g. a asterisk @*@) is set the output will be the n-times repeated char, where n is the rating.

h3. yab_review_rating_input

The form element for the rating. Should be placed in the @comment_form@ form.

*type:* input type (text, select, radio, number, range)
Default: text
The type of the form element for the rating. Valid value are @text@, @select@, @radio@, @number@ and @range@.

*html_id:* HTML id name
Default: __not set__
The HTML id attribute applied to the element.

*class:* HTML class name
Default: __not set__
The HTML/CSS class attribute applied to the element.

*reverse:* integer|string (a non-null value)
Default: 0
If reverse is given the output of the select or radio type is displayed in reverse order.

*break:* breakpoint (br|__empty__)
Default: 'br'
Breakpoints für radio intputs. Can be empty or @br@.

*default:* integer
Default: __not set__
Preselected rating value (Could be any number between your min and max values).

h3. yab_review_rating_average

Display the average rating for a given article.

*id:* string (comma-separated article ids)
Default: __no set__
The IDs of articles. If not set it must be placed in an article form (article context).

*only_visible:* integer|bool (1|0)
Default: 1
If set to 0 all comments (spam and moderated comments too) will be calculated.

*exclude:* string (a comma separated list of ratings)
Default: __null__
Exclude these ratings from the average rating calculation. So you can exclude '0' values for not rated articles, due 0 is the default value. Depending on your rating system setting.

*default:* string (Text)
Default: 'not yet rated'
The default text on articles without a rating.

*decimals:* integer
Default: 1
Define the decimal precision of the calculation and the output.

*separator:* string (string|empty)
Default: . (perdiod)
Choose your decimal separator. Can be empty (separator will be omitted) for HTML class friendly output.

*round_to_half*: string (up|down|)
Default: __no net__
Round to first half integer up or down or not at all. If not set the last decimal is automatically rounded up.

h2(#help-section09). Examples

h3. Example 1

Example of @yab_review_rating_input@ in a @comment_form@ form.

bc.. <txp:comments_error wraptag="ul" break="li" />
	<div class="message">
		<p><label for="name">Name:</label><br /><txp:comment_name_input /></p>
		<p><label for="email">Mail (not required, not visible):</label><br />
			<txp:comment_email_input /></p>
		<p><label for="yab-rr-rating">Rating</label><br />
			<txp:yab_review_rating_input html_id="yab-rr-rating" type="select" reverse="1" default="3" /></p>
		<p><label for="message">Review:</label><br />
			<txp:comment_message_input /></p>
		<p class="submit"><txp:comments_help /><txp:comment_preview /><txp:comment_submit /></p>
</div>

p. Will produce a comment form for article reviews (e.g. with yab_shop). The select dropdown menu for the rating is in reversed order (highest top) and the preselected rating value is 3.

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

p. Will produce a the a comment/review with the name, text and time of the comment and the rating as HTML/CSS class.

h3. Example 4

Example @yab_review_rating_average@.

bc.. <txp:yab_review_rating_average id="12" exclude="0" decimals="2" separator="" round_to_half="down" />

p. Say the article with the ID 12 do have 3 reviews: One with a rating of 0 and two with a rating of 4 each. The output will exclude the 0 from the calculation. So only the two 4-ratings will be used 4+4 = 8÷2 = 4. Average rating is 4. But we have decimals precision of 2, so it will be 4.00. No rounding required but the separator will be ommitted: 400 will be displayed.
exclude="0" decimals="2" separator="" round_to_half="down" />

bc.. <txp:yab_review_rating_average id="12" decimals="2" separator="" round_to_half="down" />

p. Here we calculate an average from all reviews/ratings. Like above we have two 4 and 0-rating. So the rating is 0+4+4 = 8÷3 = 2.6666666667. Now we round to half down: 2.500000000 and use the decimal precision of 2: 2.50 and ommit the separator: 250.

h2(#help-section10). Changelog

* v0.1: 2013-12-24
** initial release
* v0.2: 2014-01-08
** new: added a the tag @<txp:yab_review_rating_average />@
* v0.3: 2014-01-12
** new: added the id attribute to @<txp:yab_review_rating />@
** modify: @<txp:yab_review_rating />@ can now be used in @<txp:recent_comments />@
* v0.4: 2014-01-16
** new: added reverse attribute to @<txp:yab_review_rating_input />@
** new: added only_visible attribute to @<txp:yab_review_rating_average />@
** modify: id attribute of @<txp:yab_review_rating_average />@ can now contain list of article ids
* v0.5: 2017-02-10
** TXP 4.6-ready

h2(#help-section11). Licence

This plugin is released under the GNU General Public License Version 2 and above
* Version 2: "http://www.gnu.org/licenses/gpl-2.0.html":http://www.gnu.org/licenses/gpl-2.0.html
* Version 3: "http://www.gnu.org/licenses/gpl-3.0.html":http://www.gnu.org/licenses/gpl-3.0.html

h2(#help-section12). Author contact

* "Plugin on author's site":http://www.yablo.de/article/475/yab_review_rating-a-comment-based-rating-system-for-textpattern
* "Plugin on GitHub":https://github.com/trenc/yab_review_rating
* "Plugin on textpattern forum":http://forum.textpattern.com/viewtopic.php?id=40374
* "Plugin on textpattern.org":http://textpattern.org/plugins/1285/yab_review_rating
# --- END PLUGIN HELP ---
-->
<?php
}
?>
