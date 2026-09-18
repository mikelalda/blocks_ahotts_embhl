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
 * ReadSpeakers webReader for Moodle block.
 *
 * @package    block_ahotts_embhl
 * @copyright  2016 ReadSpeaker <info@readspeaker.com>
 * @author     Richard Risholm
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// General Strings.
$string['pluginname'] = 'ReadSpeaker webReader';
$string['readspeaker_embhl'] = 'ReadSpeaker webReader';
$string['ahotts_embhl:addinstance'] = 'Add a new webReader block';
$string['ahotts_embhl:myaddinstance'] = 'Add a new webReader block to the My Moodle page';
$string['ahotts_embhl:edit'] = 'Edit setting for the webReader block';

// Admin Configuration Strings.
$string['header_config'] = 'Configuration Options';
$string['header_config_help'] = 'Below you will find the configuration options available for the ReadSpeaker webReader plugin.';

// Block title.
$string['block_title'] = 'Listen to this page using ReadSpeaker';
$string['block_settings_title'] = 'Configure ReadSpeaker webReader';

// CustomerID.
$string['customerid'] = 'Customer ID (required)';
$string['customerid_help'] = 'Your ReadSpeaker Customer ID (example "1234").';

// Custom API.
$string['header_api'] = 'Custom API endpoints';
$string['header_api_help'] = 'Configure the base URL for the service used to generate the audio player links.';
$string['apiurl_eu'] = 'Basque API URL';
$string['apiurl_eu_help'] = 'Base URL for the Basque endpoint, for example https://tts.example.local/eu.';
$string['apiurl_es'] = 'Spanish API URL';
$string['apiurl_es_help'] = 'Base URL for the Spanish endpoint, for example https://tts.example.local/es.';
$string['apiurl_en'] = 'English API URL';
$string['apiurl_en_help'] = 'Base URL for the English endpoint, for example https://tts.example.local/en.';

// Language.
$string['lang'] = 'Reading language (required)';
$string['lang_help'] = 'Select the reading language for the Listen button (the selected language must be enabled in your ReadSpeaker account).';

// List of reading languages.
$string['afrikaans'] = 'Afrikaans';
$string['arabic'] = 'Arabic';
$string['basque'] = 'Basque';
$string['bulgarian'] = 'Bulgarian';
$string['catalan'] = 'Catalan';
$string['chinese_mandarin'] = 'Chinese (Mandarin)';
$string['chinese_taiwanese'] = 'Chinese Taiwanese Mandarin';
$string['croatian'] = 'Croatian';
$string['czech'] = 'Czech';
$string['danish'] = 'Danish';
$string['dutch'] = 'Dutch';
$string['dutch_frisian'] = 'Dutch (Frisian)';
$string['dutch_flemish'] = 'Dutch (Flemish)';
$string['english_american'] = 'English (American)';
$string['english_australian'] = 'English (Australian)';
$string['english_indian'] = 'English (Indian)';
$string['english_newzealand'] = 'English (New Zealand)';
$string['english_scottish'] = 'English (Scottish)';
$string['english_southafrican'] = 'English (South African)';
$string['english_brittish'] = 'English (UK)';
$string['estonian'] = 'Estonian';
$string['faroese'] = 'Faroese';
$string['farsi'] = 'Farsi';
$string['finnish'] = 'Finnish';
$string['french'] = 'French';
$string['french_belgian'] = 'French (Belgian)';
$string['french_canadian'] = 'French (Canadian)';
$string['gelician'] = 'Galician';
$string['hebrew'] = 'Hebrew';
$string['german'] = 'German';
$string['greek'] = 'Greek';
$string['hindi'] = 'Hindi';
$string['hong_kong_cantonese'] = 'Hong Kong Cantonese';
$string['hungarian'] = 'Hungarian';
$string['icelandic'] = 'Icelandic';
$string['isindebele'] = 'IsiNdebele';
$string['isixhosa'] = 'IsiXhosa';
$string['isizulu'] = 'IsiZulu';
$string['italian'] = 'Italian';
$string['japanese'] = 'Japanese';
$string['korean'] = 'Korean';
$string['lithuanian'] = 'Lithuanian';
$string['latvian'] = 'Latvian';
$string['sepedi'] = 'Sepedi';
$string['sesotho'] = 'Sesotho';
$string['setswana'] = 'Setswana';
$string['siswati'] = 'Siswati';
$string['spanish_argentinian'] = 'Spanish (Argentinian)';
$string['spanish_castilian'] = 'Spanish (Castilian)';
$string['spanish_american'] = 'Spanish (American)';
$string['spanish_columbian'] = 'Spanish (Columbian)';
$string['spanish_latin_american'] = 'Spanish (Latin American)';
$string['spanish_mexican'] = 'Spanish (Mexican)';
$string['norwegian_bokmal'] = 'Norwegian (Bokm&aring;l)';
$string['norwegian_nynorsk'] = 'Norwegian (Nynorska)';
$string['polish'] = 'Polish';
$string['portuguese'] = 'Portuguese';
$string['portuguese_brazilian'] = 'Portuguese (Brazilian)';
$string['romanian'] = 'Romanian';
$string['russian'] = 'Russian';
$string['swedish'] = 'Swedish';
$string['swedish_finnish'] = 'Swedish (Finnish)';
$string['thai'] = 'Thai';
$string['tshivenda'] = 'Tshivenda';
$string['turkish'] = 'Turkish';
$string['ukrainian'] = 'Ukrainian';
$string['welsh'] = 'Welsh';
$string['xitsonga'] = 'Xitsonga';


// Language.
$string['region'] = 'Region';
$string['region_help'] = 'Select the region for your ReadSpeaker installation.';

// Readid.
$string['readid'] = 'Reading area ID (required)';
$string['readid_help'] = 'The ID of the block level element which is to be read (example "region-main").';

// Region names.
$string['africa'] = 'Africa';
$string['asia'] = 'Asia';
$string['east_asia'] = 'East Asia';
$string['europe'] = 'Europe';
$string['middle_east'] = 'Middle East';
$string['north_america'] = 'North America';
$string['south_america'] = 'South America';
$string['oceania'] = 'Oceania';

// DocReader.
$string['docreader'] = 'Enable docReader';
$string['docreader_help'] = 'Check to enable docReader (docReader must be enabled in your ReadSpeaker account).';

// Player placement.
$string['showincontent'] = 'Listen button placement';
$string['showincontent_help'] = 'The Listen button is by default placed in a block in the sidebar. You can move the player to the top of the content instead.';
$string['showincontent_showinblock'] = 'Show in block';
$string['showincontent_showincontent'] = 'Show in content';

// Choosing to disable webReader in editing mode.
$string['disableinem'] = 'Advanced option: Disable in editing mode.';
$string['disableinem_help'] = 'Check this option to disable the webReader functionality when in editing mode, the block will still display on pages but the Listen button and scripts will not be loaded when in editing mode.';

// Using latest version of webReader for Education scripts.
$string['latestscript'] = 'Advanced option: Latest script version';
$string['latestscript_help'] = 'Check to use the latest version of the webReader for Education scripts for testing purposes (WARNING: latest scripts will always automatically update with new webReader for Education releases).';

// Custom parameters.
$string['customparams'] = 'Advanced option: Custom parameters';
$string['customparams_help'] = 'Specify custom parameters to the ReadSpeaker Listen button (adds in addition to default).';

// Restricted mode configuration.
$string['webreaderfeatures'] = 'webReader features';
$string['webreaderfeatures_help'] = 'This sets the mode for webReader, which in turn controls which webReader listen button features are available.';
$string['standard'] = 'Standard';
$string['restricted'] = 'Restricted';

// For cache.
$string['cachedef_readspeaker_tokens'] = 'DocReader token cache';

// Listen button text.
$string['listentext'] = "Listen";

// Playback control labels.
$string['loadingtext'] = "Loading...";
$string['pausetext'] = "Pause";
$string['resumetext'] = "Resume";
$string['stoptext'] = "Stop";

// aHoTTS API voices and limits.
$string['voice_eu'] = 'Basque voice';
$string['voice_eu_help'] = 'Voice used for Basque synthesis through the aHoTTS API (e.g. antton, maider).';
$string['voice_es'] = 'Spanish API voice';
$string['voice_es_help'] = 'Voice used as a fallback for Spanish synthesis through the aHoTTS API (e.g. laura, alejandro).';
$string['maxtextlength'] = 'Maximum text length';
$string['maxtextlength_help'] = 'Maximum number of characters sent to the synthesis engine in a single request.';

// Listen button descriptive title text.
$string['listen_titletext'] = "Listen to this page using ReadSpeaker";

// Privacy text.
$string['privacy:metadata'] = 'The ReadSpeaker block does not store any personal information and only displays the ReadSpeaker Listen button.';

// Enable statistics.
$string['statistics'] = 'Statistics: Enable page information';
$string['stats_help'] = 'Check to display the page path instead of the page URL in statistics.';

// Translation
$string['translation'] = 'Translation language';
$string['translation_help'] = 'Select the Page language or the Reading language to be used as the source language (from) for translation.';
$string['translation_page'] = 'Page language';
$string['translation_voice'] = 'Reading language';

// Mobile plugin
$string['mobileapp'] = "Enable mobile plugin";
$string['mobileapp_help'] = "Check to enable the plugin in the Moodle app (also requires the feature to be turned on by ReadSpeaker).";

// Mobile toolbar.
$string['pixels'] = 'Mobile toolbar position';
$string['pixels_help'] = 'Specify how many pixels from the bottom of the screen the mobile toolbar should be placed (example "130").';
// Cross-origin SCORM bridge.
$string['header_scorm'] = 'SCORM and external content';
$string['header_scorm_help'] = 'Content inside an iframe served from another domain cannot be read by the browser: the Same-Origin Policy forbids it. Such a package can only be read when it ships the cooperative bridge (bridge/ahotts-scorm-bridge.js) and its origin is listed below. SCORM packages served by this Moodle site are read directly and need no configuration.';
$string['scormbridge'] = 'Enable the cross-origin SCORM bridge';
$string['scormbridge_help'] = 'Allow the Listen button to ask external SCORM packages for their text through the cooperative postMessage bridge. It has no effect until at least one origin is listed below.';
$string['scormorigins'] = 'Allowed SCORM origins';
$string['scormorigins_help'] = 'One origin per line, as scheme, host and optional port with no path, for example <code>https://scorm.example.org</code> or <code>https://learning.example.org:8443</code>. A single leading wildcard label is accepted (<code>https://*.example.org</code>) and matches sub-domains only. Messages from any other origin are ignored. Entries that are not well formed origins are discarded.';
$string['scormtimeout'] = 'Bridge timeout (milliseconds)';
$string['scormtimeout_help'] = 'How long to wait for an external package to answer before treating it as having no bridge.';
$string['status_externalnobridge'] = 'Part of this page is external content that does not allow reading. Only the rest of the page will be read aloud.';
$string['status_nocontent'] = 'There is no readable text on this page.';

// Reading language.
$string['header_voices'] = 'Reading language and voices';
$string['header_voices_help'] = 'Basque is spoken by the itzune voices running inside the browser, with the aHoTTS API behind them. Every other language is spoken by the browser\'s own Web Speech API, with the aHoTTS API behind it when one is configured for that language.';
$string['langmode'] = 'How the reading language is decided';
$string['langmode_help'] = 'Choose where the reading language comes from. "Let the reader choose" adds a language menu to the block and remembers the choice in the browser.';
$string['langmode_fixed'] = 'Fixed: the language configured above';
$string['langmode_page'] = 'Follow the Moodle interface language';
$string['langmode_content'] = 'Follow the lang attribute of the content';
$string['langmode_chooser'] = 'Let the reader choose in the block';
$string['chooserlangs'] = 'Languages offered';
$string['chooserlangs_help'] = 'Languages the reader may pick from, used by the "let the reader choose" and "follow the lang attribute" modes. Basque needs either the in-browser voices or a Basque aHoTTS endpoint; the other languages need a matching voice in the reader\'s browser.';
$string['languagelabel'] = 'Language';
$string['voicelabel'] = 'Voice';

// In-browser Basque voices (itzune Piper models).
$string['header_piper'] = 'Basque voices in the browser (itzune)';
$string['header_piper_help'] = 'The itzune voices (<a href="https://huggingface.co/itzune">huggingface.co/itzune</a>) are Piper models in ONNX format, run locally by ONNX Runtime Web on WebGPU, or on WebAssembly when WebGPU is not available. Nothing is sent to a server, but the voice (around 65 MB) and the runtime are downloaded once and then kept in the browser cache. If they cannot be loaded, the block falls back to the aHoTTS API.';
$string['piperenabled'] = 'Use the itzune voices in the browser for Basque';
$string['piperenabled_help'] = 'When this is off, or when the runtime cannot start, Basque is synthesised by the aHoTTS API instead.';
$string['pipervoice'] = 'Default Basque voice';
$string['pipervoice_help'] = 'Voice used unless the reader picks another one.';
$string['pipervoice_antton'] = 'Antton (male)';
$string['pipervoice_maider'] = 'Maider (female)';
$string['pipervoicechooser'] = 'Let the reader choose the Basque voice';
$string['pipervoicechooser_help'] = 'Adds a voice menu to the block while Basque is being read.';
$string['piperbackend'] = 'Execution backend';
$string['piperbackend_help'] = 'WebGPU is faster where it is available. "Automatic" uses it when the browser offers it and falls back to WebAssembly otherwise.';
$string['piperbackend_auto'] = 'Automatic (WebGPU, then WebAssembly)';
$string['piperbackend_webgpu'] = 'WebGPU';
$string['piperbackend_wasm'] = 'WebAssembly';
$string['pipermodel_antton'] = 'Antton model URL';
$string['pipermodel_maider'] = 'Maider model URL';
$string['pipermodel_help'] = 'URL of the .onnx voice. Its configuration is read from the same URL with .json appended. Serve it from your own site to avoid depending on a third party, and make sure the response allows cross-origin reads if it is not on this domain.';
$string['piperort'] = 'ONNX Runtime Web URL';
$string['piperort_help'] = 'URL of the ONNX Runtime Web build (the UMD one, for example ort.webgpu.min.js).';
$string['piperwasmpath'] = 'ONNX Runtime WebAssembly directory';
$string['piperwasmpath_help'] = 'Directory holding the runtime\'s .wasm files, with a trailing slash.';
$string['piperphonemizer'] = 'Phonemizer URL';
$string['piperphonemizer_help'] = 'URL of the eSpeak NG phonemizer ES module, which turns text into the phonemes the voice expects.';

// Playback status.
$string['status_preparingvoice'] = 'Preparing the voice. The first time, this downloads it.';
$string['status_voicefallback'] = 'The voice in the browser could not be started; using the online service instead.';
$string['status_enginesunavailable'] = 'No voice is available for this language in this browser.';
