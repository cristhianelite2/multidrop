Multidrop AliExpress Hunter
===========================

1. En Chrome abre chrome://extensions
2. Activa "Modo de desarrollador"
3. "Cargar descomprimida" y elige esta carpeta (descomprime el ZIP antes)
4. Abre Product Hunter, copia el token del plugin y pégalo aquí (icono de la extensión → Token)
5. La URL del panel se rellena al descargar el ZIP; si cambias de dominio, edítala y pulsa Guardar
6. Visita una ficha AliExpress (/item/…), espera a que cargue, y pulsa "Enviar a Product Hunter"
   (el plugin hace scroll a Descripción / #nav-description para forzar la carga lazy)

El plugin lee el HTML ya renderizado (variantes, precio, reseñas, descripción) y lo manda a Multidrop.
Si pegas HTML a mano en Product Hunter: abre antes la pestaña Descripción en AE;
el ancla del menú (#nav-description) sola no trae el contenido.
No coloca pedidos. No hace scraping en segundo plano: solo al pulsar.
