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
 * @copyright  2022 ReadSpeaker <info@readspeaker.com>
 * @author     Richard Risholm
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// General Strings.
$string['pluginname'] = 'BirtSpeaker';

// Listen button text
$string['listentext'] = "Entzun";

// Playback control labels.
$string['loadingtext'] = "Kargatzen...";
$string['pausetext'] = "Pausatu";
$string['resumetext'] = "Jarraitu";
$string['stoptext'] = "Gelditu";

// Listen button descriptive title text
$string['listen_titletext'] = "Orri hau entzun BirtSpeaker erabiliz";
// SCORM eta kanpoko edukia.
$string['header_scorm'] = 'SCORM eta kanpoko edukia';
$string['header_scorm_help'] = 'Nabigatzaileak ezin du beste domeinu batetik zerbitzatutako iframe baten edukia irakurri: jatorri bereko politikak galarazten du. Halako pakete bat irakurgarria izango da soilik zubi lankidea (bridge/ahotts-scorm-bridge.js) badakar eta bere jatorria beheko zerrendan badago. Moodle honek berak zerbitzatzen dituen SCORMak zuzenean irakurtzen dira, konfiguraziorik gabe.';
$string['scormbridge'] = 'Gaitu jatorri arteko SCORM zubia';
$string['scormbridge_help'] = 'Entzun botoiak kanpoko SCORM paketeei testua eskatzeko aukera ematen du, postMessage bidezko zubi lankidearen bitartez. Ez du eraginik jatorri bat gutxienez zerrendatu arte.';
$string['riseproxy'] = 'Zerbitzatu Rise 360 ostatatutako ikastaroak gune honetatik';
$string['riseproxy_help'] = 'Ostatatutako edukiarekin («connected») argitaratutako Rise 360 paketeek packages.articulateusercontent.com-etik kargatzen dute ikastaroa, eta blokeak ezin du irakurri. Alderantzizko proxya Apachen instalatuta, horrelako pakete bakoitza gune honetara bideratzen da igotzean eta ikastaroa hemendik zerbitzatzen da: blokeak irakurtzen du eta SCORM jarraipenak funtzionatzen jarraitzen du. Gune honetako paketeen ikastaroak bakarrik pasatzen dira proxytik. Komando-lerrotik konfiguratzen da, ez hemendik: php blocks/ahotts_embhl/cli/rise_proxy.php --apache aginduak Apacheren konfigurazioa erakusten du, eta --enable aginduak dauden paketeak aldatzen ditu eta aukera hau aktibatzen du. Pakete bat proxyak harentzat erantzuten duenean bakarrik aldatzen da.';
$string['scormorigins'] = 'Onartutako SCORM jatorriak';
$string['scormorigins_help'] = 'Jatorri bat lerro bakoitzeko: eskema, ostalaria eta ataka aukerakoa, biderik gabe; adibidez <code>https://scorm.example.org</code>. Hasierako komodin bat onartzen da (<code>https://*.example.org</code>) eta azpidomeinuak soilik hartzen ditu. Beste jatorri guztien mezuak baztertu egiten dira.';
$string['scormtimeout'] = 'Zubiaren itxaronaldia (milisegundo)';
$string['scormtimeout_help'] = 'Kanpoko pakete batek erantzuteko itxaron beharreko denbora, zubirik ez duela erabaki aurretik.';
$string['status_externalhosted'] = 'Pakete honek egile-tresnaren zerbitzarietatik (Articulate) kargatzen du edukia, eta ezin da ozen irakurri. Orriaren gainerakoa baino ez da irakurriko.';
$string['status_externalnobridge'] = 'Orri honen zati bat kanpoko edukia da eta ez du irakurtzen uzten. Orriaren gainerakoa baino ez da irakurriko.';
$string['status_nocontent'] = 'Ez dago testu irakurgarririk orri honetan.';

// Irakurketa hizkuntza.
$string['header_voices'] = 'Irakurketa hizkuntza eta ahotsak';
$string['header_voices_help'] = 'Euskara nabigatzailearen barruan exekutatzen diren itzune ahotsekin sintetizatzen da, atzean aHoTTS APIa duela. Gainerako hizkuntzak nabigatzailearen beraren Web Speech APIarekin irakurtzen dira, eta atzean aHoTTS APIa hizkuntza horretarako konfiguratuta badago.';
$string['langmode'] = 'Nola erabakitzen den irakurketa hizkuntza';
$string['langmode_help'] = 'Aukeratu nondik datorren irakurketa hizkuntza. «Irakurleak aukeratu dezala» aukerak hizkuntza menua eransten dio blokeari eta aukera nabigatzailean gordetzen du.';
$string['langmode_fixed'] = 'Finkoa: goian konfiguratutako hizkuntza';
$string['langmode_page'] = 'Moodleren interfazearen hizkuntzari jarraitu';
$string['langmode_content'] = 'Edukiaren lang atributuari jarraitu';
$string['langmode_chooser'] = 'Irakurleak blokean aukeratu dezala';
$string['chooserlangs'] = 'Eskainitako hizkuntzak';
$string['chooserlangs_help'] = 'Irakurleak aukeratu ditzakeen hizkuntzak, «irakurleak aukeratu dezala» eta «lang atributuari jarraitu» moduek erabiltzen dituztenak. Euskarak nabigatzaileko ahotsak edo euskarazko aHoTTS endpoint bat behar du; gainerako hizkuntzek nabigatzailean ahots bateragarria behar dute.';
$string['languagelabel'] = 'Hizkuntza';
$string['voicelabel'] = 'Ahotsa';
$string['ratelabel'] = 'Abiadura';
$string['header_rate'] = 'Irakurketaren abiadura';
$string['header_rate_help'] = 'Ikasleek azkarrago edo motelago entzun dezakete. Ahotsaren tonua mantentzen da. Nabigatzailearen ahotsarekin aldaketa hurrengo esalditik aurrera aplikatzen da; gainerako ahotsekin, berehala.';
$string['ratechooser'] = 'Utzi abiadura aukeratzen';
$string['ratechooser_help'] = 'Abiadura-menu bat eransten dio blokeari (0,5×-etik 2×-era). Aukera irakurlearen nabigatzailean gordetzen da.';
$string['defaultrate'] = 'Abiadura lehenetsia';
$string['defaultrate_help'] = 'Irakurleak beste bat aukeratu arte erabiliko den abiadura. Deskargatutako audioa abiadura arruntean dago beti.';

// Euskarazko ahotsak nabigatzailean (itzuneren Piper modeloak).
$string['header_piper'] = 'Ahots neuronalak nabigatzailean';
$string['header_piper_help'] = 'Piper ahotsak ONNX modeloak dira, lokalean exekutatzen direnak ONNX Runtime Web bidez, WebGPU erabiliz edo WebAssembly erabiliz WebGPUrik ez dagoenean. Euskara itzuneren ahotsek irakurtzen dute (<a href="https://huggingface.co/itzune">huggingface.co/itzune</a>) eta gainerako hizkuntzak Piper proiektuaren ahotsek. Ez da ezer zerbitzari batera bidaltzen, baina ahots bakoitza (65 MB inguru) eta runtimea behin deskargatzen dira eta nabigatzailearen cachean gelditzen dira. Ezin badira kargatu, blokeak nabigatzailearen ahotsera edo aHoTTS APIra jotzen du.';
$string['piperenabled'] = 'Erabili ahots neuronalak nabigatzailean';
$string['piperenabled_help'] = 'Irakurlearen ordenagailuan bertan exekutatzen den ahots batekin irakurtzen du orria, beraz ez da testurik inora bidaltzen. Desaktibatuta dagoenean, edo runtimea abiarazi ezin denean, nabigatzailearen ahotsa edo aHoTTS APIa erabiltzen da. Euskarak ez du nabigatzaileko ahotsik, beraz hau gabe APIa behar du.';
$string['pipervoice_lang'] = '{$a} ahots lehenetsia';
$string['pipervoice_help'] = 'Irakurleak beste bat aukeratzen ez duen bitartean erabiliko den ahotsa.';
$string['pipervoice_antton'] = 'Antton';
$string['pipervoice_maider'] = 'Maider';
$string['pipervoice_davefx'] = 'Davefx';
$string['pipervoice_alba'] = 'Alba';
$string['pipervoice_ryan'] = 'Ryan';
$string['pipervoicelang_eu'] = 'Euskarazko';
$string['pipervoicelang_es'] = 'Gaztelaniazko';
$string['pipervoicelang_en'] = 'Ingelesezko';
$string['pipervoicechooser'] = 'Utzi ahotsa aukeratzen';
$string['pipervoicechooser_help'] = 'Irakurtzen ari den hizkuntzarako eskuragarri dauden ahotsen menua eransten dio blokeari.';
$string['piperbackend'] = 'Exekuzio motorra';
$string['piperbackend_help'] = 'WebGPU azkarragoa da eskuragarri dagoen lekuan. «Automatikoa» aukerak nabigatzaileak eskaintzen badu erabiltzen du eta bestela WebAssemblyra jotzen du.';
$string['piperbackend_auto'] = 'Automatikoa (WebGPU eta, bestela, WebAssembly)';
$string['piperbackend_webgpu'] = 'WebGPU';
$string['piperbackend_wasm'] = 'WebAssembly';
$string['pipermodel'] = '{$a} modeloaren URLa';
$string['pipermodel_help'] = 'Ahotsaren .onnx fitxategiaren URLa. Bere konfigurazioa URL horri berari .json erantsita irakurtzen da. Zerbitzatu zure gunetik hirugarrenen mende ez egoteko eta, domeinu honetan ez badago, ziurtatu erantzunak jatorri arteko irakurketak onartzen dituela.';
$string['piperort'] = 'ONNX Runtime Web URLa';
$string['piperort_help'] = 'ONNX Runtime Weben UMD konpilazioaren URLa, adibidez ort.webgpu.min.js.';
$string['piperwasmpath'] = 'ONNX Runtimeren WebAssembly direktorioa';
$string['piperwasmpath_help'] = 'Runtimearen .wasm fitxategiak dituen direktorioa, amaierako barrarekin.';
$string['piperphonemizer'] = 'Fonemizatzailearen URLa';
$string['piperphonemizer_help'] = 'eSpeak NG fonemizatzailearen ES moduluaren URLa, testua ahotsak espero dituen fonemetan bihurtzen duena. Euskara daramaten konpilazioa izan behar du: ingelesa baino ez dutenekin itzuneko ahotsak mutu geratzen dira.';
$string['piperphonemizerwasm'] = 'Fonemizatzailearen WebAssembly fitxategia';
$string['piperphonemizerwasm_help'] = 'Fonemizatzailearen .wasm fitxategiaren URLa. Behin deskargatu eta esaldi bakoitzean berrerabiltzen da, moduluari bakoitzean bilatzen uztea baino askoz azkarragoa dena. Hutsik utzi moduluak berak aurki dezan.';

// Euskarazko ahots neuronal naturalak (Pocket TTS, itzune).
$string['header_pockettts'] = 'Ahots neuronal naturalak nabigatzailean (Pocket TTS)';
$string['header_pockettts_help'] = 'Pocket TTS (Kyutai), itzunek euskarara egokitua (<a href="https://huggingface.co/itzune/pocket-tts-basque-onnx">huggingface.co/itzune/pocket-tts-basque-onnx</a>, CC BY 4.0). Piper baino naturalagoa da eta zenbakiak, datak, orduak eta ehunekoak irakurtzen ditu, baina astunagoa da: lehen aldian 125 MB inguru deskargatzen dira eta nabigatzailearen cachean gelditzen dira, eta gailu nahiko berria behar du. Atzeko planoko bi prozesutan exekutatzen da, beraz prozesadorearen bi nukleo erabiltzen ditu. Abiarazi ezin denean, Piper ahotsa erabiltzen da. Kredituak: Kyutai Pocket TTS, HiTZ-Aholab estudioko corpusa (Maider eta Antton) eta BSSD datu-multzoa.';
$string['pocketttsenabled'] = 'Erabili Pocket TTS ahotsak';
$string['pocketttsenabled_help'] = 'Euskara Pocket TTS ahotsekin irakurtzen du, Piperren aurretik. Ez da ezer zerbitzari batera bidaltzen.';
$string['pocketttsvoice_lang'] = '{$a} Pocket TTS ahots lehenetsia';
$string['pocketttsvoice_help'] = 'Irakurleak beste bat aukeratzen ez duen bitartean erabiliko den ahotsa.';
$string['pocketttsvoice_maider'] = 'Maider';
$string['pocketttsvoice_antton'] = 'Antton';
$string['pocketttsvoice_nahia'] = 'Nahia';
$string['pocketttsvoice_ane'] = 'Ane';
$string['pocketttsvoice_aitor'] = 'Aitor';
$string['pocketttsbaseurl'] = '{$a} Pocket TTS modeloaren karpeta';
$string['pocketttsbaseurl_help'] = 'Modeloaren bundle.json, .onnx fitxategiak, tokenizatzailea eta voices/ karpeta dituen karpeta, amaierako barrarekin. Hutsik utzi argitaratutako modeloa erabiltzeko. Zuk zeuk ostatatzeko, kopiatu flow_lm_main_int8.onnx, flow_lm_flow_int8.onnx, mimi_decoder_int8.onnx, text_conditioner.onnx, bundle.json, vocab.json, token_scores.json eta voices/*.safetensors, eta onartu jatorri arteko irakurketak beste domeinu batean badago.';
$string['pocketttsort'] = 'Pocket TTSrako ONNX Runtime Web modulua';
$string['pocketttsort_help'] = 'ONNX Runtime Weben ES modulu bertsioaren URLa (ort.wasm.min.mjs), Pocket TTS prozesuek inportatzen dutena.';
$string['pocketttswasmpath'] = 'Pocket TTSrako ONNX Runtimeren WebAssembly direktorioa';
$string['pocketttswasmpath_help'] = 'Bertsio horren .wasm fitxategiak dituen direktorioa, amaierako barrarekin.';

// Erreprodukzioaren egoera.
$string['status_preparingvoice'] = 'Ahotsa prestatzen. Lehen aldian deskargatu egin behar da.';
$string['status_preparingvoiceprogress'] = 'Ahotsa deskargatzen (% {percent}). Lehen aldian bakarrik gertatzen da.';
$string['status_voicefallback'] = 'Ezin izan da nabigatzaileko ahotsa abiarazi; lineako zerbitzua erabiliko da.';
$string['status_enginesunavailable'] = 'Ez dago ahotsik eskuragarri hizkuntza honetarako nabigatzaile honetan.';

// Offline audio download.
$string['downloadaudio'] = 'Deskargatu audioa';
$string['downloadpdffile'] = 'Deskargatu PDFa';
$string['saveaudio'] = 'Gorde audioa';
$string['status_downloadunavailable'] = 'Audioa deskargatzeko, tokiko ahots bat edo hizkuntza honetarako konfiguratutako audio API bat behar da. Nabigatzailearen ahotsa ezin da esportatu.';
$string['status_downloadprogress'] = 'Audioa prestatzen: {current}. zatia, guztira {total}.';
$string['status_downloadready'] = 'Audioa prest dago. Gorde fitxategia konexiorik gabe entzuteko.';
$string['status_downloaderror'] = 'Ezin izan da audio osoa sortu. Saiatu berriro.';
$string['status_downloadcancelled'] = 'Audioaren deskarga bertan behera utzi da.';
$string['status_readingactivity'] = 'Jarduera osoa irakurtzen...';
$string['status_pdfnotfound'] = 'Ez da PDF irakurgarririk aurkitu. Erabili Moodleko domeinu berean dagoen PDF digitala, edo sartu SCORM paketean.';
$string['status_pdferror'] = 'Ezin izan da PDFaren testu osoa atera. Egiaztatu PDF digitala eta saiatu berriro.';
$string['status_pdfdownloadstarted'] = 'PDFaren deskarga hasi da.';
$string['scormpdf'] = 'Sortu PDF bat SCORM paketeetatik';
$string['scormpdf_help'] = 'SCORM jarduera batean, «Deskargatu PDFa» aukerak argitaratutako paketearen fitxategietatik sortutako PDF bat eskaintzen du: Rise 360ko ikasgaiak, Storyline 360ko diapositibak edo imsmanifest.xml fitxategiak zerrendatzen dituen orriak (eXeLearning eta beste). Paketea irakurri egiten da, ez da erreproduzitzen; beraz, ikasleen aurrerapena ez da ukitzen. Testua eta irudiak gordetzen dira, baina ez diseinua ezta elkarreragina ere, eta galderak erantzunik gabe agertzen dira. Paketea ordezten den bakoitzean PDFa berriro sortzen da.';
$string['scormpdfdisabled'] = 'SCORM paketeetatik PDFak sortzea desgaituta dago.';
$string['scormpdfempty'] = 'Ez da testu irakurgarririk aurkitu SCORM pakete honetan.';
$string['scormpdffailed'] = 'Ezin izan da ikastaroaren PDFa sortu. Arrazoia zerbitzariaren erregistroan idatzi da.';
$string['status_pdfgenerating'] = 'Ikastaroaren PDFa prestatzen. Lehen aldian minutu bat behar dezake.';
