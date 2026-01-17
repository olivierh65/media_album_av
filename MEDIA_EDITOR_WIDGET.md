# Media Album Editor Widget

## Overview

The **Media Album Editor Widget** (`media_album_av_media_editor`) provides an inline editing interface for media items within album nodes. It allows users to:

- ✅ View and manage media in an album
- ✅ Edit media attributes (title, description, tags, categories)
- ✅ Reorder media via drag & drop
- ✅ Remove media from albums
- ✅ View media metadata
- ✅ Quick link to full media edit forms

## Features

### 1. Media List View

Displays all media referenced in the field with:
- Media thumbnail/icon
- Media type badge (Photo, Video, Audio)
- Quick access actions

### 2. Inline Editing

For each media item:
- Expandable details panel with all editable fields
- Field widgets built using centralized `FieldWidgetBuilderTrait`
- Automatic field type detection
- Support for all media field types (string, text, entity_reference, etc.)

### 3. Metadata Display

Shows read-only information:
- Media type
- Created date
- Modified date
- Other relevant metadata

### 4. Drag & Drop Reordering

- Click and drag media items to reorder them
- Visual feedback during dragging
- Preserved order saved with form

### 5. Quick Actions

For each media:
- **Remove** - Remove from album with confirmation
- **Full Edit** - Open media entity in new tab for complete editing

## Usage

### 1. Configure a Media Reference Field

Add an entity reference field to your album node type:

```php
// In your module's .install or config:
$field_storage = FieldStorageConfig::create([
  'field_name' => 'field_media_items',
  'entity_type' => 'node',
  'type' => 'entity_reference',
  'settings' => [
    'target_type' => 'media',
  ],
]);

$field = FieldConfig::create([
  'field_storage' => $field_storage,
  'bundle' => 'media_album_av',
  'label' => 'Media Items',
  'settings' => [
    'handler' => 'default:media',
    'handler_settings' => [
      'target_bundles' => [
        'media_album_av_photo' => 'media_album_av_photo',
        'media_album_av_video' => 'media_album_av_video',
      ],
    ],
  ],
]);
```

### 2. Assign the Widget to the Field

In your field display settings, select:
- **Widget**: "Media Editor (Album)"
- **Module**: `media_album_av`

### 3. Configure Widget Settings

Available settings:
- **Allow inline editing** - Enable/disable inline attribute editing
- **Show metadata** - Display media metadata section

### Example Configuration

```yaml
# config/install/core.entity_form_display.node.media_album_av.default.yml

content:
  field_media_items:
    type: media_album_av_media_editor
    region: content
    settings:
      allow_edit: true
      show_metadata: true
      editable_fields: []
    third_party_settings: {}
```

## Supported Field Types

The widget automatically handles all media field types:

- `string`, `string_long` → textfield
- `text`, `text_long`, `text_with_summary` → textarea
- `integer`, `decimal`, `float` → textfield
- `boolean` → checkbox
- `list_string`, `list_integer` → select
- `entity_reference` → entity autocomplete
- `image`, `file` → Excluded (media file fields)
- `video_file`, `audio_file` → Excluded

## Architecture

### Dependencies

```
media_album_av (album management)
    ↓ depends on
media_field_representations (shared field utilities)
    ↓ provides
FieldWidgetBuilderTrait
```

### Widget Class Hierarchy

```
WidgetBase
    ↓
MediaAlbumEditorWidget
    ├─ uses FieldWidgetBuilderTrait
    ├─ implements ContainerFactoryPluginInterface
    └─ injected: EntityTypeManagerInterface
```

### Methods

**Public Methods:**
- `formElement()` - Build the main widget form
- `settingsForm()` - Widget settings configuration
- `massageFormValues()` - Process submitted values

**Protected Methods:**
- `getMediaItems()` - Get all referenced media
- `buildMediaEditorForm()` - Build editor for single media
- `buildMediaTitle()` - Create media title with badge
- `buildMediaMetadata()` - Display metadata section
- `buildEditableFields()` - Generate field editing forms
- `buildMediaActions()` - Create action buttons

**AJAX Callbacks:**
- `submitAddMedia()` - Handle "Add media" button
- `submitRemoveMedia()` - Handle "Remove" button
- `ajaxRefreshMediaList()` - Refresh widget via AJAX

## JavaScript Behavior

### `mediaAlbumEditor` Behavior

Located in: `js/media-editor.js`

Provides:
- **Drag & Drop**: Reorder media items
- **Field Watchers**: Detect changes to mark form as modified
- **Quick Actions**: Confirmation dialogs for destructive actions

### Events

- `dragstart` - Media item dragging starts
- `dragover` - Another item is dragged over
- `drop` - Media item dropped in new position
- `change` - Field value modified

## CSS Classes

Main containers:
- `.media-album-editor-widget` - Main widget container
- `.media-list-container` - Media items list
- `.media-editor-item` - Individual media item (details element)
- `.media-type-badge` - Media type indicator
- `.media-metadata` - Metadata section
- `.media-edit-fields` - Editable fields container
- `.media-actions` - Action buttons container

Button classes:
- `.button-add-media` - Add media button
- `.button-danger` - Destructive actions (red)
- `.button-edit` - Edit link (blue)

## Future Enhancements

Potential additions:
- [ ] Media browser integration for adding media
- [ ] Bulk edit mode
- [ ] Media sorting/ordering persistence
- [ ] Field customization per media type
- [ ] Media preview/thumbnail display
- [ ] Nested details for media relationships
- [ ] Export/Import media lists
- [ ] Media versioning history

## Example: Custom Media Album Form

```php
// In your custom form controller:

public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL) {
  $form['#tree'] = TRUE;
  
  // Get the media field.
  $media_field = $node->get('field_media_items');
  
  // Build the widget form.
  $form['media'] = [
    '#type' => 'fieldset',
    '#title' => $this->t('Album Media'),
  ];
  
  $form['media']['items'] = [
    '#type' => 'entity_reference',
    '#target_type' => 'media',
    '#widget_id' => 'media_album_av_media_editor',
    '#default_value' => $media_field->getValue(),
  ];
  
  return $form;
}
```

## Troubleshooting

### Widget Not Appearing

1. Ensure `media_album_av` module is enabled
2. Check field type is `entity_reference` with `media` target
3. Verify widget selection in field display settings

### Editing Not Working

1. Check media entity permissions
2. Ensure media has editable custom fields
3. Verify field types are supported (not image/file)

### Drag & Drop Not Working

1. Check browser console for JS errors
2. Ensure CSS is loaded: `css/media-editor.css`
3. Verify JS is loaded: `js/media-editor.js`

## Related Documentation

- [Media Album AV](../README.md)
- [Media Field Representations](../../media_field_representations/README.md)
- [Media Attributes Manager](../modules/media_attributes_manager)
