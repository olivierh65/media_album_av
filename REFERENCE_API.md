# 📚 Référence complète des fonctions et méthodes - Module media_album_av

**Date**: 9 avril 2026  
**Module**: media_album_av  
**Lieu**: `/web/modules/custom/media_album_av`  

> Ce document rassemble TOUTES les fonctions et méthodes publiques du module, avec leurs paramètres d'entrée, types de retour et descriptions.

---

## 📋 Table des matières

1. [Contrôleurs](#contrôleurs)
2. [Formulaires](#formulaires)
3. [Services](#services)
4. [Traits](#traits)
5. [Plugins Field & Views](#plugins-field--views)

---

## Contrôleurs

### AlbumFileDownloadController
📄 Fichier: `src/Controller/AlbumFileDownloadController.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `download()` | `$request` (Request), `$scheme` (string = 'private') | **BinaryFileResponse\|Response** | Télécharge fichier privé album après validation HMAC et vérification droits d'accès nœud |
| 2 | `buildUri()` | `$request` (Request), `$scheme` (string) | **string** | Reconstruit URI fichier depuis requête en concaténant schéma avec chemin |
| 3 | `fileIsReferencedByAlbum()` | `$uri` (string) | **bool** | Vérifie si fichier est référencé par au moins un nœud média d'album |

---

### TaxonomyManagerController
📄 Fichier: `src/Controller/TaxonomyManagerController.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$entity_type_manager` (EntityTypeManagerInterface), `$album_config` (AlbumConfigService), `$directory_service` (DirectoryService) | **void** | Constructeur - Injecte les 3 services : gestionnaire entités, config album, service répertoire |
| 2 | `create()` | `$container` (ContainerInterface) | **TaxonomyManagerController** | Factory method statique - Crée instance via DI |
| 3 | `modal()` | `$vocabulary_id` (string) | **array** | Affiche fenêtre modale pour gérer termes taxonomie avec interface AJAX |

---

### AlbumImageStyleDownloadController
📄 Fichier: `src/Controller/AlbumImageStyleDownloadController.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `deliver()` | `$request` (Request), `$scheme` (string), `$image_style` (ImageStyleInterface), `$required_derivative_scheme` (string) | **BinaryFileResponse\|Response** | Fournit image dérivée privée album après validation HMAC et droits d'accès |

---

## Formulaires

### MediaAlbumAvCheckForm
📄 Fichier: `src/Form/MediaAlbumAvCheckForm.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$checker` (MediaAlbumAvChecker) | **void** | Constructeur - Injecte le service de vérification albums |
| 2 | `create()` | `$container` (ContainerInterface) | **MediaAlbumAvCheckForm** | Factory method - Crée instance via DI |
| 3 | `getFormId()` | *(aucun)* | **string** | Retourne ID unique du formulaire : 'media_album_av_check_form' |
| 4 | `buildForm()` | `$form` (array), `$form_state` (FormStateInterface) | **array** | Construit formulaire affichant tous albums avec détails média, fichiers et statuts |

---

### MediaAlbumAvRepairForm
📄 Fichier: `src/Form/MediaAlbumAvRepairForm.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$checker` (MediaAlbumAvIntegrityChecker) | **void** | Constructeur - Injecte service vérification intégrité |
| 2 | `create()` | `$container` (ContainerInterface) | **MediaAlbumAvRepairForm** | Factory method statique - Crée instance via DI |
| 3 | `getFormId()` | *(aucun)* | **string** | Retourne ID du formulaire : 'media_album_av_repair_form' |
| 4 | `buildForm()` | `$form` (array), `$form_state` (FormStateInterface) | **array** | Construit formulaire montrant albums avec références cassées et options réparation |

---

### CreateAlbumForm
📄 Fichier: `src/Form/CreateAlbumForm.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | 7 paramètres : EntityTypeManagerInterface, EntityFieldManagerInterface, MessengerInterface, RendererInterface, AlbumConfigService, ConfigFactoryInterface, LoggerChannelFactoryInterface | **void** | Constructeur - Injecte les 7 services nécessaires pour créer albums |
| 2 | `create()` | `$container` (ContainerInterface) | **CreateAlbumForm** | Factory method statique - Crée instance via DI |
| 3 | `getFormId()` | *(aucun)* | **string** | Retourne ID du formulaire pour création album |
| 4 | `buildForm()` | `$form` (array), `$form_state` (FormStateInterface) | **array** | Construit formulaire pour créer nouvel album avec taxonomies |

---

### AlbumTaxonomyManagerForm
📄 Fichier: `src/Form/AlbumTaxonomyManagerForm.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$entity_type_manager` (EntityTypeManagerInterface), `$messenger` (MessengerInterface) | **void** | Constructeur - Injecte gestionnaire entités et service messages |
| 2 | `create()` | `$container` (ContainerInterface) | **AlbumTaxonomyManagerForm** | Factory method statique - Crée instance via DI |
| 3 | `getFormId()` | *(aucun)* | **string** | Retourne 'media_album_av_taxonomy_manager_form' |
| 4 | `buildForm()` | `$form` (array), `$form_state` (FormStateInterface), `$vocabulary_id` (string = NULL) | **array** | Crée interface ajouter/éditer/supprimer termes taxonomie avec hiérarchie |
| 5 | `loadTermsHierarchy()` | `$vocabulary_id` (string) | **array** | Charge les termes organisés hiérarchiquement par parent |
| 6 | `renderTermTree()` | `$by_parent` (array), `$vocabulary_id` (string), `$parent_id` (int = 0) | **string** | Génère HTML pour afficher arborescence termes |
| 7 | `getParentOptions()` | `$by_parent` (array) | **array** | Crée options pour sélecteur parent avec indentation |
| 8 | `addChildOptions()` | `&$options` (array), `$by_parent` (array), `$parent_id` (int), `$prefix` (string) | **void** | Ajoute récursivement options termes enfants avec indentation |
| 9 | `submitAddTerm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Traite soumission pour ajouter nouveau terme |
| 10 | `ajaxRefreshTerms()` | `&$form` (array), `$form_state` (FormStateInterface) | **AjaxResponse** | Actualise arborescence termes via AJAX après ajout |

---

### MediaAlbumAvSettingsForm
📄 Fichier: `src/Form/MediaAlbumAvSettingsForm.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$entity_type_manager` (EntityTypeManagerInterface), `$entity_field_manager` (EntityFieldManagerInterface) | **void** | Constructeur - Injecte services pour gérer champs |
| 2 | `create()` | `$container` (ContainerInterface) | **MediaAlbumAvSettingsForm** | Factory method statique - Crée instance via DI |
| 3 | `getEditableConfigNames()` | *(aucun)* | **array** | Retourne clés config éditables : ['media_album_av.settings'] |
| 4 | `getFormId()` | *(aucun)* | **string** | Retourne 'media_album_av_settings_form' |
| 5 | `buildForm()` | `$form` (array), `$form_state` (FormStateInterface) | **array** | Crée formulaire config avec tabs auteurs, groupement, types média |
| 6 | `getNodeAuthorFields()` | *(aucun)* | **array** | Récupère champs nœud acceptant auteurs |
| 7 | `getNodeStringLongFields()` | *(aucun)* | **array** | Retourne champs "long text" nœud pour config groupement |
| 8 | `getContentTypes()` | *(aucun)* | **array** | Liste tous types contenu disponibles |
| 9 | `getVocabularies()` | *(aucun)* | **array** | Récupère toutes vocabulaires taxonomie |
| 10 | `getMediaTypesByCategory()` | `$category` (string) | **array** | Filtre types média par catégorie (image ou vidéo) |
| 11 | `getStreamWrappers()` | *(aucun)* | **array** | Retourne flux disponibles (public, private) |
| 12 | `getAcceptedMediaBundles()` | *(aucun)* | **array** | Extrait bundles média acceptés du champ référence nœud |
| 13 | `getDefaultAuthorField()` | `$media_type_id` (string) | **string\|null** | Détermine champ auteur par défaut pour type média |

---

## Services

### MediaAlbumAvIntegrityChecker
📄 Fichier: `src/Service/MediaAlbumAvIntegrityChecker.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `check()` | *(aucun)* | **array** | Vérifie tous albums et retourne ceux ayant références cassées avec media IDs manquants |
| 2 | `repair()` | `$nids` (array = []) | **int** | Supprime références cassées albums spécifiés, retourne nombre supprimé |

---

### ConfiguredFieldsService
📄 Fichier: `src/Service/ConfiguredFieldsService.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$config_factory` (ConfigFactoryInterface) | **void** | Constructeur - Injecte service config |
| 2 | `getCategoryField()` | `$media_type_id` (string) | **string\|null** | Retourne champ catégorie configuré pour type média |
| 3 | `getCategoryFieldConfig()` | `$media_type_id` (string) | **array\|null** | Retourne config complète champ catégorie (field_name, autocreate) |
| 4 | `getAuthorField()` | `$media_type_id` (string) | **string\|null** | Retourne champ auteur configuré pour type média |
| 5 | `isCategoryAutocreateEnabled()` | `$media_type_id` (string) | **bool** | Vérifie si autocréation catégories activée pour type média |

---

### AlbumConfigService
📄 Fichier: `src/Service/AlbumConfigService.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$config_factory` (ConfigFactoryInterface) | **void** | Constructeur - Injecte service config |
| 2 | `getAlbumContentType()` | *(aucun)* | **string** | Retourne type contenu album configuré (défaut: 'media_album_av') |
| 3 | `getEventVocabulary()` | *(aucun)* | **string\|null** | Retourne ID vocabulaire événement ou NULL |
| 4 | `getPreferredMediaDirectoryVocabulary()` | *(aucun)* | **string\|null** | Retourne vocabulaire répertoire média préféré |
| 5 | `getDateField()` | *(aucun)* | **string** | Retourne champ date configuré |
| 6 | `getEventField()` | *(aucun)* | **string** | Retourne champ événement configuré |
| 7 | `getDirectoryField()` | *(aucun)* | **string** | Retourne champ répertoire média configuré |

---

### MediaAlbumAvChecker
📄 Fichier: `src/Service/MediaAlbumAvChecker.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$entityTypeManager` (EntityTypeManagerInterface), `$fileSystem` (FileSystemInterface) | **void** | Constructeur - Injecte services entité et système fichier |
| 2 | `getAlbumsDetails()` | *(aucun)* | **array** | Retourne tous albums avec détails média : delta, ID, nom, statut, fichiers existants |
| 3 | `getMediaFiles()` | `$media` (MediaInterface) | **array** | Retourne tous fichiers associés à média avec info existence, taille, statut |
| 4 | `formatBytes()` | `$bytes` (int) | **string** | Formate octets en format lisible (B, KB, MB, GB) |

---

## Traits

### HmacValidatorTrait
📄 Fichier: `src/HmacValidatorTrait.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `validateHmac()` | `$request` (Request) | **bool** | Valide jeton HMAC-Base64 envoyé requête pour fichiers privés |

---

## Plugins Field & Views

### MediaAlbumEditorWidget (Field Widget)
📄 Fichier: `src/Plugin/Field/FieldWidget/MediaAlbumEditorWidget.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `formElement()` | `$items` (FieldItemListInterface), `$delta` (int), `$element` (array), `&$form` (array), `$form_state` (FormStateInterface) | **array** | Construit widget éditeur pour sélectionner et organiser médias d'album |
| 2 | `massageFormValues()` | `$values` (array), `$form` (array), `$form_state` (FormStateInterface) | **array** | Traite valeurs formulaire pour sauvegarder base données |

---

### MediaUnifiedField (Views Field)
📄 Fichier: `src/Plugin/views/field/MediaUnifiedField.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `query()` | *(aucun)* | **void** | Intentionnellement vide - pas d'impact sur requête SQL |
| 2 | `defineOptions()` | *(aucun)* | **array** | Définit options champ Views : media_fields, view_mode avec defaults |
| 3 | `buildOptionsForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Crée formulaire config pour sélectionner champs média et mode d'affichage |
| 4 | `render()` | `$values` (ResultRow) | **array** | Rendu champ en affichant premier champ média non vide du média |
| 5 | `getMediaFieldsFromView()` | *(aucun)* | **array** | Récupère champs média déjà présents dans vue |
| 6 | `getMediaFieldsFromEntity()` | *(aucun)* | **array** | Liste tous champs média (image/file) tous bundles média |
| 7 | `getMediaSourceFields()` | *(aucun)* | **array** | Retourne champs source médias (image, file) sans base fields |

---

## 📊 Résumé statistique

| Catégorie | Total |
|-----------|-------|
| **Contrôleurs** | 3 classes × 3-4 méthodes |
| **Formulaires** | 5 classes × 4-10 méthodes |
| **Services** | 4 classes × 2-7 méthodes |
| **Traits** | 1 × 1 méthode |
| **Plugins Field** | 1 × 2 méthodes |
| **Plugins Views** | 1 × 7 méthodes |
| **Total fichiers PHP** | 16 |
| **Total fonctions/méthodes** | **45+** |

---

## 🎯 Architecture du module

Ce module gère la **gestion avancée des albums médias** avec :

1. **Téléchargement de fichiers privés** (AlbumFileDownloadController) - Validation HMAC, droits d'accès
2. **Gestion des taxonomies** (TaxonomyManagerController + Formulaires) - Création/édition/suppression termes
3. **Création d'albums** (CreateAlbumForm) - Multi-étape avec taxonomies et champs
4. **Vérification d'intégrité** (MediaAlbumAvChecker, MediaAlbumAvIntegrityChecker) - Détection références cassées
5. **Configuration** (MediaAlbumAvSettingsForm) - Mappages champs, auteurs, groupement
6. **Édition média intégrée** (MediaAlbumEditorWidget) - Widget pour sélectionner/organiser médias

---

## 🔧 Comment utiliser ce document

1. **Télécharger fichier privé**: Consultez AlbumFileDownloadController
2. **Gérer taxonomies**: Consultez TaxonomyManagerController + AlbumTaxonomyManagerForm
3. **Créer album**: Consultez CreateAlbumForm
4. **Vérifier intégrité**: Consultez MediaAlbumAvChecker et MediaAlbumAvIntegrityChecker
5. **Configurer**: Consultez MediaAlbumAvSettingsForm

---

**Dernière mise à jour**: 9 avril 2026
