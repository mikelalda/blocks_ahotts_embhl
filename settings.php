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

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_heading(
        'header_config',
        get_string('header_config', 'block_ahotts_embhl'),
        get_string('header_config_help', 'block_ahotts_embhl')
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/cid',
        get_string('customerid', 'block_ahotts_embhl'),
        get_string('customerid_help', 'block_ahotts_embhl'),
        0,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/readid',
        get_string('readid', 'block_ahotts_embhl'),
        get_string('readid_help', 'block_ahotts_embhl'),
        'region-main',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_heading(
        'header_api',
        get_string('header_api', 'block_ahotts_embhl'),
        get_string('header_api_help', 'block_ahotts_embhl')
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/apiurl_eu',
        get_string('apiurl_eu', 'block_ahotts_embhl'),
        get_string('apiurl_eu_help', 'block_ahotts_embhl'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/apiurl_es',
        get_string('apiurl_es', 'block_ahotts_embhl'),
        get_string('apiurl_es_help', 'block_ahotts_embhl'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/apiurl_en',
        get_string('apiurl_en', 'block_ahotts_embhl'),
        get_string('apiurl_en_help', 'block_ahotts_embhl'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/voice_eu',
        get_string('voice_eu', 'block_ahotts_embhl'),
        get_string('voice_eu_help', 'block_ahotts_embhl'),
        'antton',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/voice_es',
        get_string('voice_es', 'block_ahotts_embhl'),
        get_string('voice_es_help', 'block_ahotts_embhl'),
        'laura',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/maxtextlength',
        get_string('maxtextlength', 'block_ahotts_embhl'),
        get_string('maxtextlength_help', 'block_ahotts_embhl'),
        '3000',
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'header_voices',
        get_string('header_voices', 'block_ahotts_embhl'),
        get_string('header_voices_help', 'block_ahotts_embhl')
    ));

    $settings->add(new admin_setting_configselect(
        'block_ahotts_embhl/langmode',
        get_string('langmode', 'block_ahotts_embhl'),
        get_string('langmode_help', 'block_ahotts_embhl'),
        'fixed',
        array(
            'fixed' => get_string('langmode_fixed', 'block_ahotts_embhl'),
            'page' => get_string('langmode_page', 'block_ahotts_embhl'),
            'content' => get_string('langmode_content', 'block_ahotts_embhl'),
            'chooser' => get_string('langmode_chooser', 'block_ahotts_embhl')
        )
    ));

    $settings->add(new admin_setting_configmultiselect(
        'block_ahotts_embhl/chooserlangs',
        get_string('chooserlangs', 'block_ahotts_embhl'),
        get_string('chooserlangs_help', 'block_ahotts_embhl'),
        array('eu', 'es', 'en'),
        array(
            'eu' => get_string('basque', 'block_ahotts_embhl'),
            'es' => get_string('spanish_castilian', 'block_ahotts_embhl'),
            'en' => get_string('english_brittish', 'block_ahotts_embhl'),
            'fr' => get_string('french', 'block_ahotts_embhl'),
            'ca' => get_string('catalan', 'block_ahotts_embhl'),
            'gl' => get_string('gelician', 'block_ahotts_embhl'),
            'de' => get_string('german', 'block_ahotts_embhl'),
            'it' => get_string('italian', 'block_ahotts_embhl'),
            'pt' => get_string('portuguese', 'block_ahotts_embhl')
        )
    ));

    $settings->add(new admin_setting_heading(
        'header_piper',
        get_string('header_piper', 'block_ahotts_embhl'),
        get_string('header_piper_help', 'block_ahotts_embhl')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_ahotts_embhl/piperenabled',
        get_string('piperenabled', 'block_ahotts_embhl'),
        get_string('piperenabled_help', 'block_ahotts_embhl'), 1)
    );

    // One default voice per language, and one model URL per voice, so a site can
    // host the models itself instead of reaching out to Hugging Face.
    foreach (\block_ahotts_embhl\local\languages::PIPER_VOICES as $piperlang => $pipervoices) {
        $choices = array();
        foreach (array_keys($pipervoices) as $voiceid) {
            $choices[$voiceid] = get_string('pipervoice_' . $voiceid, 'block_ahotts_embhl');
        }

        $settings->add(new admin_setting_configselect(
            'block_ahotts_embhl/pipervoice_' . $piperlang,
            get_string('pipervoice_lang', 'block_ahotts_embhl',
                get_string('pipervoicelang_' . $piperlang, 'block_ahotts_embhl')),
            get_string('pipervoice_help', 'block_ahotts_embhl'),
            \block_ahotts_embhl\local\languages::DEFAULT_PIPER_VOICES[$piperlang],
            $choices
        ));
    }

    $settings->add(new admin_setting_configcheckbox(
        'block_ahotts_embhl/pipervoicechooser',
        get_string('pipervoicechooser', 'block_ahotts_embhl'),
        get_string('pipervoicechooser_help', 'block_ahotts_embhl'), 1)
    );

    $settings->add(new admin_setting_configselect(
        'block_ahotts_embhl/piperbackend',
        get_string('piperbackend', 'block_ahotts_embhl'),
        get_string('piperbackend_help', 'block_ahotts_embhl'),
        'auto',
        array(
            'auto' => get_string('piperbackend_auto', 'block_ahotts_embhl'),
            'webgpu' => get_string('piperbackend_webgpu', 'block_ahotts_embhl'),
            'wasm' => get_string('piperbackend_wasm', 'block_ahotts_embhl')
        )
    ));

    foreach (\block_ahotts_embhl\local\languages::PIPER_VOICES as $pipervoices) {
        foreach ($pipervoices as $voiceid => $modelurl) {
            $settings->add(new admin_setting_configtext(
                'block_ahotts_embhl/pipermodel_' . $voiceid,
                get_string('pipermodel', 'block_ahotts_embhl',
                    get_string('pipervoice_' . $voiceid, 'block_ahotts_embhl')),
                get_string('pipermodel_help', 'block_ahotts_embhl'),
                $modelurl,
                PARAM_URL
            ));
        }
    }

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/piperort',
        get_string('piperort', 'block_ahotts_embhl'),
        get_string('piperort_help', 'block_ahotts_embhl'),
        'https://cdn.jsdelivr.net/npm/onnxruntime-web@1.22.0/dist/ort.webgpu.min.js',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/piperwasmpath',
        get_string('piperwasmpath', 'block_ahotts_embhl'),
        get_string('piperwasmpath_help', 'block_ahotts_embhl'),
        'https://cdn.jsdelivr.net/npm/onnxruntime-web@1.22.0/dist/',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/piperphonemizer',
        get_string('piperphonemizer', 'block_ahotts_embhl'),
        get_string('piperphonemizer_help', 'block_ahotts_embhl'),
        \block_ahotts_embhl\local\languages::DEFAULT_PHONEMIZER,
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/piperphonemizerwasm',
        get_string('piperphonemizerwasm', 'block_ahotts_embhl'),
        get_string('piperphonemizerwasm_help', 'block_ahotts_embhl'),
        \block_ahotts_embhl\local\languages::DEFAULT_PHONEMIZER_WASM,
        PARAM_URL
    ));

    $settings->add(new admin_setting_heading(
        'header_scorm',
        get_string('header_scorm', 'block_ahotts_embhl'),
        get_string('header_scorm_help', 'block_ahotts_embhl')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_ahotts_embhl/scormbridge',
        get_string('scormbridge', 'block_ahotts_embhl'),
        get_string('scormbridge_help', 'block_ahotts_embhl'), 0)
    );

    $settings->add(new admin_setting_configtextarea(
        'block_ahotts_embhl/scormorigins',
        get_string('scormorigins', 'block_ahotts_embhl'),
        get_string('scormorigins_help', 'block_ahotts_embhl'),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/scormtimeout',
        get_string('scormtimeout', 'block_ahotts_embhl'),
        get_string('scormtimeout_help', 'block_ahotts_embhl'),
        '2500',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'block_ahotts_embhl/lang',
        get_string('lang', 'block_ahotts_embhl'),
        get_string('lang_help', 'block_ahotts_embhl'),
        'en_us',
        array(
            'af_za' => get_string('afrikaans', 'block_ahotts_embhl'),
            'ar_ar' => get_string('arabic', 'block_ahotts_embhl'),
            'bg_bg' => get_string('bulgarian', 'block_ahotts_embhl'),
            'eu_es' => get_string('basque', 'block_ahotts_embhl'),
            'ca_es' => get_string('catalan', 'block_ahotts_embhl'),
            'zh_cn' => get_string('chinese_mandarin', 'block_ahotts_embhl'),
            'zh_tw' => get_string('chinese_taiwanese', 'block_ahotts_embhl'),
            'hr_hr' => get_string('croatian', 'block_ahotts_embhl'),
            'cs_cz' => get_string('czech', 'block_ahotts_embhl'),
            'da_dk' => get_string('danish', 'block_ahotts_embhl'),
            'nl_nl' => get_string('dutch', 'block_ahotts_embhl'),
            'fy_nl' => get_string('dutch_frisian', 'block_ahotts_embhl'),
            'nl_be' => get_string('dutch_flemish', 'block_ahotts_embhl'),
            'en_us' => get_string('english_american', 'block_ahotts_embhl'),
            'en_au' => get_string('english_australian', 'block_ahotts_embhl'),
            'en_in' => get_string('english_indian', 'block_ahotts_embhl'),
            'en_nz' => get_string('english_newzealand', 'block_ahotts_embhl'),
            'en_sc' => get_string('english_scottish', 'block_ahotts_embhl'),
            'en_za' => get_string('english_southafrican', 'block_ahotts_embhl'),
            'en_uk' => get_string('english_brittish', 'block_ahotts_embhl'),
            'et_ee' => get_string('estonian', 'block_ahotts_embhl'),
            'fo_fo' => get_string('faroese', 'block_ahotts_embhl'),
            'fa_ir' => get_string('farsi', 'block_ahotts_embhl'),
            'fi_fi' => get_string('finnish', 'block_ahotts_embhl'),
            'fr_fr' => get_string('french', 'block_ahotts_embhl'),
            'fr_be' => get_string('french_belgian', 'block_ahotts_embhl'),
            'fr_ca' => get_string('french_canadian', 'block_ahotts_embhl'),
            'gl_es' => get_string('gelician', 'block_ahotts_embhl'),
            'he_il' => get_string('hebrew', 'block_ahotts_embhl'),
            'de_de' => get_string('german', 'block_ahotts_embhl'),
            'el_gr' => get_string('greek', 'block_ahotts_embhl'),
            'hi_in' => get_string('hindi', 'block_ahotts_embhl'),
            'zh_hk' => get_string('hong_kong_cantonese', 'block_ahotts_embhl'),
            'hu_hu' => get_string('hungarian', 'block_ahotts_embhl'),
            'is_is' => get_string('icelandic', 'block_ahotts_embhl'),
            'nr_za' => get_string('isindebele', 'block_ahotts_embhl'),
            'xh_za' => get_string('isixhosa', 'block_ahotts_embhl'),
            'zu_za' => get_string('isizulu', 'block_ahotts_embhl'),
            'it_it' => get_string('italian', 'block_ahotts_embhl'),
            'ja_jp' => get_string('japanese', 'block_ahotts_embhl'),
            'ko_kr' => get_string('korean', 'block_ahotts_embhl'),
            'lt_lt' => get_string('lithuanian', 'block_ahotts_embhl'),
            'lv_lv' => get_string('latvian', 'block_ahotts_embhl'),
            'nso' => get_string('sepedi', 'block_ahotts_embhl'),
            'st_za' => get_string('sesotho', 'block_ahotts_embhl'),
            'tn_za' => get_string('setswana', 'block_ahotts_embhl'),
            'ss_za' => get_string('siswati', 'block_ahotts_embhl'),
            'es_ar' => get_string('spanish_argentinian', 'block_ahotts_embhl'),
            'es_es' => get_string('spanish_castilian', 'block_ahotts_embhl'),
            'es_us' => get_string('spanish_american', 'block_ahotts_embhl'),
            'es_co' => get_string('spanish_columbian', 'block_ahotts_embhl'),
            'es_mx' => get_string('spanish_mexican', 'block_ahotts_embhl'),
            'es_419' => get_string('spanish_latin_american', 'block_ahotts_embhl'),
            'no_nb' => get_string('norwegian_bokmal', 'block_ahotts_embhl'),
            'no_nn' => get_string('norwegian_nynorsk', 'block_ahotts_embhl'),
            'pl_pl' => get_string('polish', 'block_ahotts_embhl'),
            'pt_pt' => get_string('portuguese', 'block_ahotts_embhl'),
            'pt_br' => get_string('portuguese_brazilian', 'block_ahotts_embhl'),
            'ro_ro' => get_string('romanian', 'block_ahotts_embhl'),
            'ru_ru' => get_string('russian', 'block_ahotts_embhl'),
            'sv_se' => get_string('swedish', 'block_ahotts_embhl'),
            'sv_fi' => get_string('swedish_finnish', 'block_ahotts_embhl'),
            'th_th' => get_string('thai', 'block_ahotts_embhl'),
            've_za' => get_string('tshivenda', 'block_ahotts_embhl'),
            'tr_tr' => get_string('turkish', 'block_ahotts_embhl'),
            'uk_ua' => get_string('ukrainian', 'block_ahotts_embhl'),
            'cy_cy' => get_string('welsh', 'block_ahotts_embhl'),
            'ts_za' => get_string('xitsonga', 'block_ahotts_embhl')
        )
    ));

    $settings->add(new admin_setting_configselect(
        'block_ahotts_embhl/translation',
        get_string('translation', 'block_ahotts_embhl'),
        get_string('translation_help', 'block_ahotts_embhl'),
        '0',
        array(
                '0' => get_string('translation_page', 'block_ahotts_embhl'),
                '1' => get_string('translation_voice', 'block_ahotts_embhl')
            )
    ));

    $settings->add(new admin_setting_configselect(
        'block_ahotts_embhl/region',
        get_string('region', 'block_ahotts_embhl'),
        get_string('region_help', 'block_ahotts_embhl'),
        'eu',
        array(
            'af' => get_string('africa', 'block_ahotts_embhl'),
            'as' => get_string('asia', 'block_ahotts_embhl'),
            'eas' => get_string('east_asia', 'block_ahotts_embhl'),
            'eu' => get_string('europe', 'block_ahotts_embhl'),
            'me' => get_string('middle_east', 'block_ahotts_embhl'),
            'na' => get_string('north_america', 'block_ahotts_embhl'),
            'sa' => get_string('south_america', 'block_ahotts_embhl'),
            'oc' => get_string('oceania', 'block_ahotts_embhl')
        )
    ));

    $settings->add(new admin_setting_configselect(
        'block_ahotts_embhl/showincontent',
        get_string('showincontent', 'block_ahotts_embhl'),
        get_string('showincontent_help', 'block_ahotts_embhl'),
        '1',
        array(
                '0' => get_string('showincontent_showinblock', 'block_ahotts_embhl'),
                '1' => get_string('showincontent_showincontent', 'block_ahotts_embhl')
            )
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_ahotts_embhl/docreader',
        get_string('docreader', 'block_ahotts_embhl'),
        get_string('docreader_help', 'block_ahotts_embhl'), false)
    );

    $settings->add(new admin_setting_configcheckbox(
        'block_ahotts_embhl/disableinem',
        get_string('disableinem', 'block_ahotts_embhl'),
        get_string('disableinem_help', 'block_ahotts_embhl'), false)
    );

    $settings->add(new admin_setting_configcheckbox(
        'block_ahotts_embhl/latestscript',
        get_string('latestscript', 'block_ahotts_embhl'),
        get_string('latestscript_help', 'block_ahotts_embhl'), false)
    );

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/customparams',
        get_string('customparams', 'block_ahotts_embhl'),
        get_string('customparams_help', 'block_ahotts_embhl'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_ahotts_embhl/statistics',
        get_string('statistics', 'block_ahotts_embhl'),
        get_string('stats_help', 'block_ahotts_embhl'), false)
    );

    $settings->add(new admin_setting_configcheckbox(
        'block_ahotts_embhl/mobileapp',
        get_string('mobileapp', 'block_ahotts_embhl'),
        get_string('mobileapp_help', 'block_ahotts_embhl'), false),
    );

    $settings->add(new admin_setting_configtext(
        'block_ahotts_embhl/pixels',
        get_string('pixels', 'block_ahotts_embhl'),
        get_string('pixels_help', 'block_ahotts_embhl'),
        '130',
        PARAM_RAW
    ));
}
