/**
 * @file
 * Auto-download of album export archives when the batch finishes.
 *
 * Compatible avec les archives multi-part : les URLs sont déclenchées en
 * séquence (une toutes les 1,5 s) pour éviter de saturer le navigateur et
 * laisser chaque téléchargement démarrer. Chaque part bénéficie du support
 * HTTP Range côté serveur (reprise en cas de coupure).
 */
(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.mediaAlbumAvExportAutoDownload = {
    attach: function (context) {
      var cfg = drupalSettings.mediaAlbumAvExportAutoDownload;
      if (!cfg || !cfg.urls || !cfg.urls.length) {
        return;
      }
      // Une seule fois par chargement de page (le batch recharge la page).
      if (context !== document || Drupal.behaviors.mediaAlbumAvExportAutoDownload.done) {
        return;
      }
      Drupal.behaviors.mediaAlbumAvExportAutoDownload.done = true;

      var urls = cfg.urls.slice();
      var delay = 1500;

      function downloadNext(index) {
        if (index >= urls.length) {
          return;
        }
        var a = document.createElement('a');
        a.href = urls[index];
        a.setAttribute('download', '');
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        if (index + 1 < urls.length) {
          setTimeout(function () {
            downloadNext(index + 1);
          }, delay);
        }
      }

      // Petit délai pour laisser le message de fin de batch s'afficher.
      setTimeout(function () {
        downloadNext(0);
      }, 800);
    }
  };
  Drupal.behaviors.mediaAlbumAvExportAutoDownload.done = false;

})(Drupal, drupalSettings);
