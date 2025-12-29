<?php

namespace Drupal\media_album_av_directories\Plugin\views\argument;

use Drupal\media_directories\Plugin\views\argument\MediaDirectoryArgument;
use Drupal\views\ViewExecutable;
use Drupal\views\Plugin\views\display\DisplayPluginBase;

/**
 * Media Album AV directory argument.
 *
 * @ViewsArgument(
 *   id = "media_album_av_directory"
 * )
 */
class MediaAlbumAVDirectory extends MediaDirectoryArgument {

  /**
   * The real field.
   *
   * The actual field in the database table, maybe different
   * on other kind of query plugins/special handlers.
   *
   * @var string
   */
  public $realField = 'directory';
  /**
   * The table this handler is attached to.
   *
   * @var string
   */
  public $table = 'media_field_data';

  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, ?array &$options = NULL) {
    parent::init($view, $display, $options);

    // Forcer l'utilisation de la taxonomie media_album_av_folders
    // Cette option sera transmise au JS et accessible partout.
    $this->options['vocabulary'] = 'media_album_av_folders';
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    // Définir le vocabulaire comme option de la vue.
    $options['vocabulary'] = ['default' => 'media_album_av_folders'];

    return $options;
  }

  /**
   *
   */
  public function adminLabel($short = FALSE): string {
    return $this->t('Media Album AV directory');
  }

  /**
   * {@inheritdoc}
   */
  public function query($group_by = FALSE) {
    $this->ensureMyTable();
    $config = $this->configFactory->get('media_directories.settings');

    $placeholder = $this->placeholder();
    $null_check = empty($this->options['not']) ? '' : " OR $this->tableAlias.$this->realField IS NULL";

    $new_group = $this->query->setWhereGroup();
    if ((int) $this->argument === MEDIA_DIRECTORY_ROOT) {
      if ($config->get('all_files_in_root')) {
        // Show everything.
        $this->query->setWhereGroup('OR', $new_group);
        $this->query->addWhereExpression($new_group, "$this->tableAlias.$this->realField IS NOT NULL");
      }
      $this->query->addWhereExpression($new_group, "$this->tableAlias.$this->realField IS NULL");
    }
    else {
      $operator = empty($this->options['not']) ? '=' : '!=';
      $this->query->addWhereExpression($new_group, "$this->tableAlias.$this->realField $operator $placeholder" . $null_check, [$placeholder => $this->argument]);
    }

  }

}
