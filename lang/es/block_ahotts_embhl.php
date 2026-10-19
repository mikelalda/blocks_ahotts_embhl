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
$string['listentext'] = "Escuchar";

// Playback control labels.
$string['loadingtext'] = "Cargando...";
$string['pausetext'] = "Pausar";
$string['resumetext'] = "Reanudar";
$string['stoptext'] = "Detener";

// Listen button descriptive title text
$string['listen_titletext'] = "Escucha esta página utilizando BirtSpeaker";
// SCORM y contenido externo.
$string['header_scorm'] = 'SCORM y contenido externo';
$string['header_scorm_help'] = 'El navegador no puede leer el contenido de un iframe servido desde otro dominio: lo impide la política de mismo origen. Un paquete así solo se puede leer si incorpora el puente cooperativo (bridge/ahotts-scorm-bridge.js) y su origen figura en la lista. Los SCORM servidos por este propio Moodle se leen directamente y no necesitan configuración.';
$string['scormbridge'] = 'Activar el puente SCORM entre orígenes';
$string['scormbridge_help'] = 'Permite que el botón Escuchar pida su texto a paquetes SCORM externos mediante el puente cooperativo basado en postMessage. No surte efecto hasta que se indique al menos un origen.';
$string['scormorigins'] = 'Orígenes SCORM permitidos';
$string['scormorigins_help'] = 'Un origen por línea: esquema, host y puerto opcional, sin ruta; por ejemplo <code>https://scorm.example.org</code>. Se admite un comodín inicial (<code>https://*.example.org</code>) que solo cubre subdominios. Se ignoran los mensajes de cualquier otro origen.';
$string['scormtimeout'] = 'Tiempo de espera del puente (milisegundos)';
$string['scormtimeout_help'] = 'Tiempo de espera a que responda un paquete externo antes de considerar que no tiene puente.';
$string['status_externalhosted'] = 'Este paquete carga su contenido desde los servidores de la herramienta de autor (Articulate), por lo que no se puede leer en voz alta. Solo se leerá el resto de la página.';
$string['status_externalnobridge'] = 'Parte de esta página es contenido externo que no permite la lectura. Solo se leerá el resto de la página.';
$string['status_nocontent'] = 'No hay texto legible en esta página.';

// Idioma de lectura.
$string['header_voices'] = 'Idioma de lectura y voces';
$string['header_voices_help'] = 'El euskera se sintetiza con las voces de itzune dentro del navegador, con la API de aHoTTS por detrás. El resto de idiomas se leen con la Web Speech API del propio navegador, con la API de aHoTTS por detrás cuando hay una configurada para ese idioma.';
$string['langmode'] = 'Cómo se decide el idioma de lectura';
$string['langmode_help'] = 'Elige de dónde sale el idioma de lectura. «Que lo elija quien lee» añade un menú de idioma al bloque y recuerda la elección en el navegador.';
$string['langmode_fixed'] = 'Fijo: el idioma configurado arriba';
$string['langmode_page'] = 'Seguir el idioma de la interfaz de Moodle';
$string['langmode_content'] = 'Seguir el atributo lang del contenido';
$string['langmode_chooser'] = 'Que lo elija quien lee, en el bloque';
$string['chooserlangs'] = 'Idiomas ofrecidos';
$string['chooserlangs_help'] = 'Idiomas entre los que se puede elegir, usados por los modos «que lo elija quien lee» y «seguir el atributo lang». El euskera necesita las voces en el navegador o un endpoint aHoTTS en euskera; los demás idiomas necesitan una voz compatible en el navegador.';
$string['languagelabel'] = 'Idioma';
$string['voicelabel'] = 'Voz';
$string['ratelabel'] = 'Velocidad';
$string['header_rate'] = 'Velocidad de lectura';
$string['header_rate_help'] = 'El alumnado puede escuchar más rápido o más despacio. Se mantiene el tono de la voz. Con la voz propia del navegador el cambio se aplica a partir de la siguiente frase; con las demás voces, en el momento.';
$string['ratechooser'] = 'Permitir elegir la velocidad';
$string['ratechooser_help'] = 'Añade al bloque un menú de velocidad (de 0,5× a 2×). La elección se recuerda en el navegador del lector.';
$string['defaultrate'] = 'Velocidad predeterminada';
$string['defaultrate_help'] = 'Velocidad que se usa hasta que el lector elija otra. El audio descargado siempre va a velocidad normal.';

// Voces de euskera en el navegador (modelos Piper de itzune).
$string['header_piper'] = 'Voces neuronales en el navegador (Piper)';
$string['header_piper_help'] = 'Las voces Piper son modelos ONNX que se ejecutan localmente con ONNX Runtime Web sobre WebGPU, o sobre WebAssembly cuando no hay WebGPU. El euskera lo leen las voces de itzune (<a href="https://huggingface.co/itzune">huggingface.co/itzune</a>) y el resto de idiomas, voces del proyecto Piper. No se envía nada a ningún servidor, pero cada voz (unos 65 MB) y el runtime se descargan una vez y quedan en la caché del navegador. Si no se pueden cargar, el bloque recurre a la voz del navegador o a la API de aHoTTS.';
$string['piperenabled'] = 'Usar voces neuronales en el navegador';
$string['piperenabled_help'] = 'Lee la página con una voz que se ejecuta en el equipo de quien lee, así que no se envía texto a ninguna parte. Si se desactiva, o si el runtime no arranca, se usa la voz del navegador o la API de aHoTTS. El euskera no tiene voz de navegador, así que sin esto necesita la API.';
$string['pipervoice_lang'] = 'Voz por defecto para {$a}';
$string['pipervoice_help'] = 'Voz usada mientras quien lee no elija otra.';
$string['pipervoice_antton'] = 'Antton (euskera, masculina)';
$string['pipervoice_maider'] = 'Maider (euskera, femenina)';
$string['pipervoice_davefx'] = 'Davefx (castellano de España, masculina)';
$string['pipervoice_alba'] = 'Alba (inglés británico, femenina)';
$string['pipervoice_ryan'] = 'Ryan (inglés americano, masculina)';
$string['pipervoicelang_eu'] = 'euskera';
$string['pipervoicelang_es'] = 'castellano';
$string['pipervoicelang_en'] = 'inglés';
$string['pipervoicechooser'] = 'Permitir elegir la voz';
$string['pipervoicechooser_help'] = 'Añade al bloque un menú con las voces disponibles para el idioma que se está leyendo.';
$string['piperbackend'] = 'Motor de ejecución';
$string['piperbackend_help'] = 'WebGPU es más rápido donde está disponible. «Automático» lo usa si el navegador lo ofrece y si no recurre a WebAssembly.';
$string['piperbackend_auto'] = 'Automático (WebGPU y, si no, WebAssembly)';
$string['piperbackend_webgpu'] = 'WebGPU';
$string['piperbackend_wasm'] = 'WebAssembly';
$string['pipermodel'] = 'URL del modelo de {$a}';
$string['pipermodel_help'] = 'URL del fichero .onnx de la voz. Su configuración se lee de esa misma URL añadiéndole .json. Sírvelo desde tu propio sitio para no depender de terceros y, si no está en este dominio, asegúrate de que la respuesta permite lecturas entre orígenes.';
$string['piperort'] = 'URL de ONNX Runtime Web';
$string['piperort_help'] = 'URL de la compilación UMD de ONNX Runtime Web, por ejemplo ort.webgpu.min.js.';
$string['piperwasmpath'] = 'Directorio WebAssembly de ONNX Runtime';
$string['piperwasmpath_help'] = 'Directorio con los ficheros .wasm del runtime, con barra final.';
$string['piperphonemizer'] = 'URL del fonemizador';
$string['piperphonemizer_help'] = 'URL del módulo ES del fonemizador eSpeak NG, que convierte el texto en los fonemas que espera la voz. Tiene que ser una compilación que incluya el euskera: las que solo llevan inglés dejan mudas las voces de itzune.';
$string['piperphonemizerwasm'] = 'Fichero WebAssembly del fonemizador';
$string['piperphonemizerwasm_help'] = 'URL del fichero .wasm del fonemizador. Se descarga una vez y se reutiliza en cada frase, lo que es mucho más rápido que dejar que el módulo lo busque cada vez. Déjalo vacío para que lo localice el propio módulo.';

// Voces neuronales naturales en euskera (Pocket TTS, itzune).
$string['header_pockettts'] = 'Voces neuronales naturales en el navegador (Pocket TTS)';
$string['header_pockettts_help'] = 'Pocket TTS (Kyutai), ajustado para euskera por itzune (<a href="https://huggingface.co/itzune/pocket-tts-basque-onnx">huggingface.co/itzune/pocket-tts-basque-onnx</a>, CC BY 4.0). Suena más natural que Piper y lee números, fechas, horas y porcentajes, pero pesa más: la primera vez se descargan unos 125 MB que quedan en la caché del navegador, y necesita un dispositivo razonablemente reciente. Se ejecuta en dos procesos en segundo plano, así que usa dos núcleos del procesador. Si no puede arrancar, se usa la voz de Piper. Créditos: Kyutai Pocket TTS, el corpus de estudio de HiTZ-Aholab (Maider y Antton) y el conjunto de datos BSSD.';
$string['pocketttsenabled'] = 'Usar las voces Pocket TTS';
$string['pocketttsenabled_help'] = 'Lee el euskera con las voces Pocket TTS, por delante de Piper. No se envía nada a ningún servidor.';
$string['pocketttsvoice_lang'] = 'Voz Pocket TTS predeterminada para {$a}';
$string['pocketttsvoice_help'] = 'Voz que se usa salvo que el lector elija otra.';
$string['pocketttsvoice_maider'] = 'Maider (natural, femenina)';
$string['pocketttsvoice_antton'] = 'Antton (natural, masculina)';
$string['pocketttsvoice_nahia'] = 'Nahia (natural, femenina)';
$string['pocketttsvoice_ane'] = 'Ane (natural, femenina)';
$string['pocketttsvoice_aitor'] = 'Aitor (natural, masculina)';
$string['pocketttsbaseurl'] = 'Carpeta del modelo Pocket TTS para {$a}';
$string['pocketttsbaseurl_help'] = 'Carpeta con bundle.json, los ficheros .onnx, el tokenizador y la carpeta voices/ del modelo, con barra final. Déjelo vacío para usar el modelo publicado. Para alojarlo usted mismo, copie flow_lm_main_int8.onnx, flow_lm_flow_int8.onnx, mimi_decoder_int8.onnx, text_conditioner.onnx, bundle.json, vocab.json, token_scores.json y voices/*.safetensors, y permita lecturas entre orígenes si está en otro dominio.';
$string['pocketttsort'] = 'Módulo de ONNX Runtime Web para Pocket TTS';
$string['pocketttsort_help'] = 'URL de la versión en módulo ES de ONNX Runtime Web (ort.wasm.min.mjs), que importan los procesos de Pocket TTS.';
$string['pocketttswasmpath'] = 'Directorio WebAssembly de ONNX Runtime para Pocket TTS';
$string['pocketttswasmpath_help'] = 'Directorio con los ficheros .wasm de esa versión, con barra final.';

// Estado de la reproducción.
$string['status_preparingvoice'] = 'Preparando la voz. La primera vez hay que descargarla.';
$string['status_preparingvoiceprogress'] = 'Descargando la voz ({percent} %). Solo ocurre la primera vez.';
$string['status_voicefallback'] = 'No se ha podido arrancar la voz del navegador; se usa el servicio en línea.';
$string['status_enginesunavailable'] = 'No hay ninguna voz disponible para este idioma en este navegador.';

// Offline audio download.
$string['downloadaudio'] = 'Descargar audio';
$string['downloadpdffile'] = 'Descargar PDF';
$string['saveaudio'] = 'Guardar audio';
$string['status_downloadunavailable'] = 'La descarga requiere una voz local o una API de audio configurada para este idioma. La voz propia del navegador no se puede exportar.';
$string['status_downloadprogress'] = 'Preparando audio: fragmento {current} de {total}.';
$string['status_downloadready'] = 'Audio listo. Guarda el archivo para escucharlo sin conexión.';
$string['status_downloaderror'] = 'No se ha podido generar el audio completo. Vuelve a intentarlo.';
$string['status_downloadcancelled'] = 'Descarga de audio cancelada.';
$string['status_readingactivity'] = 'Leyendo toda la actividad...';
$string['status_pdfnotfound'] = 'No se ha encontrado un PDF legible. Usa un PDF digital servido desde el mismo dominio de Moodle o inclúyelo en el paquete SCORM.';
$string['status_pdferror'] = 'No se ha podido extraer todo el texto del PDF. Comprueba que sea un PDF digital e inténtalo de nuevo.';
$string['status_pdfdownloadstarted'] = 'Ha comenzado la descarga del PDF.';
$string['scormpdf'] = 'Generar un PDF de los paquetes SCORM';
$string['scormpdf_help'] = 'En una actividad SCORM, «Descargar PDF» ofrece un PDF generado a partir de los archivos del paquete publicado: las lecciones de Rise 360, las diapositivas de Storyline 360 o las páginas que lista imsmanifest.xml (eXeLearning y otros). El paquete se lee, nunca se reproduce, así que no se toca el progreso del alumnado. Se conservan el texto y las imágenes, pero no la maquetación ni la interactividad, y las preguntas aparecen sin sus respuestas. El PDF se regenera cada vez que se reemplaza el paquete.';
$string['scormpdfdisabled'] = 'La generación de PDF a partir de paquetes SCORM está desactivada.';
$string['scormpdfempty'] = 'No se ha encontrado texto legible en este paquete SCORM.';
$string['scormpdffailed'] = 'No se ha podido generar el PDF del curso. La causa ha quedado registrada en el log del servidor.';
$string['status_pdfgenerating'] = 'Preparando el PDF del curso. La primera vez puede tardar un minuto.';
