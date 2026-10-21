# Español de Perú para OpenCart

Paquete de idioma **es-pe**, con tratamiento formal, para **OpenCart 4.1.0.4**. Traduce la administración, la tienda, los correos y las extensiones nativas incluidas en esa versión.

La base es [burbuja/opencart-spanish, 4.1.0.4+bbj1](https://github.com/burbuja/opencart-spanish/tree/4.1.0.4%2Bbbj1), distribuida bajo GNU GPLv3. Esta adaptación conserva la licencia y atribución, incorpora formatos de Perú, revisa el tratamiento formal y añade un instalador independiente y pruebas. Los commits de referencia están fijados en `upstream.json`; consulte `NOTICE` y `LICENSE`.

## Compatibilidad y alcance

- OpenCart **4.1.0.4**. Otras versiones requieren verificar sus claves y su integración antes de declararlas compatibles.
- PHP **8.1 o posterior**, `pdo_mysql`, y los requisitos normales de OpenCart. La administración usa una conexión PDO/MySQL no persistente, con prepared statements reales, obtenida de las constantes `DB_*` de OpenCart.
- MariaDB/MySQL con tablas nativas **InnoDB**. La validación automatizada usa MariaDB 12.0.
- Código: `es-pe`. Nombre: **Español (Perú)**. Decimal: punto; millares: coma; fecha: `dd/mm/aaaa`; hora: formato de 24 horas. Las fechas se presentan numéricamente para evitar nombres de meses o días en inglés del motor PHP.
- El paquete cubre los **301 archivos de referencia** de administración, catálogo y extensiones nativas. No modifica el asistente inicial de instalación de OpenCart.

Los textos comerciales guardados en la base de datos —productos, categorías, páginas informativas y otros contenidos— no se traducen automáticamente. Al agregar el idioma por primera vez se copian los contenidos del idioma de administración actual, siguiendo las mismas familias de datos del modelo nativo de OpenCart. Así el catálogo continúa mostrando productos; sus traducciones pueden editarse después. No se traduce el contenido de extensiones o temas de terceros.

El idioma no cambia la moneda, la zona horaria, los impuestos ni las funciones de facturación de la tienda. Esas opciones se administran por separado.

## Construcción e instalación

1. Ejecute `python3 scripts/build.py`. Obtendrá `dist/yuei_spanish_pe.ocmod.zip` y `dist/SHA256SUMS`.
2. En una tienda de desarrollo con OpenCart 4.1.0.4, abra **Extensions > Installer**, suba ese ZIP e instale el paquete.
3. Abra **Extensions > Extensions**, seleccione **Languages** e instale **Spanish (Peru)**. Este paso registra y habilita `es-pe`.
4. En **System > Settings > Local**, seleccione **Spanish (Peru)** para el idioma de cada tienda y, si lo desea, para la administración. También puede utilizar el selector de idioma del panel o de la tienda.
5. Compruebe el catálogo, el carrito, los formularios, los correos y sus módulos habituales antes de publicarlo.

El archivo debe conservar el nombre **yuei_spanish_pe.ocmod.zip**: OpenCart utiliza ese nombre para determinar la carpeta y el namespace de la extensión. No debe subirse el ZIP automático de “Source code” de GitHub al instalador.

El paquete contiene `install.json`, `admin/`, `catalog/`, `system/`, `LICENSE` y `NOTICE` en su raíz. Todos los archivos se instalan dentro de `extension/yuei_spanish_pe/`; los textos de las extensiones nativas se incluyen bajo el namespace de idioma correspondiente. No se reemplazan archivos del núcleo ni de `extension/opencart/`.

Si ya existe un idioma `es-pe` de otro proveedor, la instalación no lo sustituye. Primero debe resolver esa coincidencia desde la administración de idiomas, conservando los contenidos que necesite. No edite las tablas para forzar una instalación.

## Deshabilitar, reinstalar y retirar

- Para cambiar el idioma predeterminado, utilice la configuración nativa de cada tienda y de la administración.
- Desinstalar **la extensión de idioma** desde **Extensions > Extensions > Languages** deshabilita `es-pe` y conserva su identificador y contenidos. Se bloquea si todavía es el idioma predeterminado de la administración o de alguna tienda.
- Reinstalarla vuelve a habilitar el mismo registro sin sobrescribir las descripciones que haya editado el comerciante.
- Para **retirar por completo el paquete**, cambie antes los idiomas predeterminados y elimine expresamente el registro **Español (Perú)** desde **System > Localisation > Languages**. La eliminación nativa de un idioma también elimina su contenido traducido: haga una copia de los contenidos que quiera conservar. Después retire el paquete desde **Extensions > Installer**.
- El instalador impide retirar los archivos mientras el registro `es-pe` todavía exista, incluso deshabilitado. Así evita dejar una configuración que apunte a archivos ausentes. Al retirarlo correctamente se limpian los eventos y las opciones propios de la extensión.

## Persistencia y seguridad

No se crean tablas ni se añade una capa de caché. La extensión usa `DB_PREFIX`, consultas PDO preparadas y los identificadores de idioma nativos. Los nombres dinámicos de columnas se obtienen de la metadata del servidor para una lista cerrada de tablas; el prefijo y los identificadores se validan antes de formar SQL.

La instalación y desactivación se ejecutan dentro de transacciones, con un bloqueo consultivo de MariaDB/MySQL compartido por los procesos que usan este paquete. Se evita duplicar el idioma en instalaciones simultáneas, se conserva el contenido al reinstalar y se rechaza apropiarse de un registro `es-pe` de otro proveedor. Las operaciones externas que no respeten ese bloqueo siguen las garantías del núcleo de OpenCart.

Las operaciones administrativas utilizan el flujo de instalación nativo, sesión, `user_token` y permiso `modify` de `extension/language`. No se añaden formularios ni endpoints AJAX propios para modificar contenido. La pantalla informativa escapa sus URLs en Twig. Se incluyen encabezados de seguridad HTTP y una CSP de compatibilidad (`base-uri`, `object-src`, `frame-ancestors`); la política completa del sitio corresponde a su servidor y a las extensiones instaladas.

La lectura de traducciones de la tienda utiliza exclusivamente el cargador nativo de OpenCart. No abre conexiones adicionales ni incorpora JavaScript, fuentes o librerías externas. Se invalida la caché nativa de idiomas después de los cambios administrativos; no se introduce otra capa.

## Verificación automatizada

```sh
find package tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/translations.php /ruta/al/codigo/oficial-de-opencart-4.1.0.4
python3 scripts/build.py
```

GitHub Actions comprueba:

- Sintaxis PHP, UTF-8, claves de todos los archivos de referencia y secuencia de placeholders (`%s`, `%d`, etc.).
- Archivos de traducción limitados a datos, sin scripts o manejadores HTML de eventos.
- Separadores numéricos, formatos de fecha y recursos del idioma de Perú.
- Subida del ZIP y extracción paginada mediante el instalador real de OpenCart.
- Rollback ante un fallo de base de datos y primera instalación concurrente desde dos procesos PHP.
- Activación en la administración, catálogo, página de producto y extensiones nativas.
- Reinstalación sin pérdida de contenido, control de idiomas predeterminados de otras tiendas y bloqueo de retirada de archivos con el idioma registrado.

Cada ejecución correcta deja durante 30 días el artefacto **spanish-peru-0.1.0**, con el ZIP instalable, su suma SHA-256 y el informe `coverage.json`. El repositorio conserva el código fuente necesario para reconstruirlos. Para la distribución pública estable, el paquete instalable se adjuntará a una versión de **Releases** después de la revisión.

La cobertura técnica de claves no certifica por sí sola la calidad de cada traducción. Las mejoras terminológicas pueden proponerse mediante issues y pull requests, indicando la pantalla, la clave y el texto sugerido. Mantenga los placeholders y el HTML controlado de las cadenas.
