# WPMMCC ATS — Plugin multilingüe para WordPress

**WordPress multilingüe y práctico: escanear, relacionar, traducir.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | **Español** | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS es un plugin de WordPress que convierte un sitio monolingüe en un sitio multilingüe. Escanea plugins de contenido, temas y menús en busca de campos traducibles, genera reglas de traducción reutilizables, gestiona las relaciones de sitio entre idiomas e incluye un editor de traducción manual completo dentro de wp-admin. La traducción automática está disponible con el cliente complementario WPTSALL Client: sin cuenta, sin licencia y sin dependencia de proveedor.

## Características
- Escaneo de contenido: detecta campos traducibles en entradas, taxonomías, meta, plugins de contenido de terceros y temas
- Relaciones de sitio: enlaza idioma de origen y destino, con sitios virtuales y su propio prefijo de URL
- Editor de traducción manual: traduce entradas y campos por completo dentro de la administración de WordPress
- Reglas de traducción: un mismo conjunto de reglas para el editor manual y la API del cliente
- API de cliente Protocol v2: permite a WPTSALL Client reclamar tareas y escribir los resultados
- Paquetes de idioma: inglés integrado; chino simplificado en progreso (ver abajo)

## Requisitos
- WordPress 6.2 o superior
- PHP 7.4 o superior
- Sin cuenta, suscripción ni clave de licencia

## Instalación
1. Descarga el ZIP de la última versión desde la página Releases de este repositorio (o comprime el código fuente tú mismo)
2. En wp-admin ve a Plugins → Añadir nuevo → Subir plugin, sube el ZIP y actívalo
3. Abre el menú WPMMCC ATS en wp-admin

## Inicio rápido (traducción manual, sin cliente)
1. Escanea tus plugins de contenido o crea un conjunto de reglas desde las páginas de administración de WPMMCC ATS
2. Añade una relación de sitio: elige idioma de origen y destino; un sitio virtual obtiene su propio prefijo de URL, p. ej. /en_us/
3. Abre una entrada en el editor de traducción manual y tradúcela; al guardar, el sitio destino se actualiza

## Traducción automática
Para traducción automática, instala el cliente complementario WPTSALL Client (WebUI o Desktop). Se conecta directamente a tu sitio con un token de dispositivo, reclama tareas de traducción, llama al proveedor que configures y escribe los resultados. Repositorio del cliente: https://github.com/wpmmcc/wptsall-client

## Languages
El inglés es el idioma de origen integrado. Un paquete de chino simplificado (zh_CN) está en progreso en languages/; una vez compilado el catálogo, elige el idioma del sitio en Ajustes → General y WordPress lo carga automáticamente. Para añadir otro idioma, traduce languages/wpmmcc-ats.pot con tu editor de PO favorito y contribúyelo.

## License
GPL-2.0-or-later. Ver [LICENSE](../LICENSE).

