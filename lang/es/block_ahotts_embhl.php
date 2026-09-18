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
$string['pipervoice_claude'] = 'Claude (castellano de México, femenina)';
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

// Estado de la reproducción.
$string['status_preparingvoice'] = 'Preparando la voz. La primera vez hay que descargarla.';
$string['status_voicefallback'] = 'No se ha podido arrancar la voz del navegador; se usa el servicio en línea.';
$string['status_enginesunavailable'] = 'No hay ninguna voz disponible para este idioma en este navegador.';
