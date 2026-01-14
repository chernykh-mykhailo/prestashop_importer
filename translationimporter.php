<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class TranslationImporter extends Module
{
    public function __construct()
    {
        $this->name = 'translationimporter';
        $this->tab = 'administration';
        $this->version = '1.0.1';
        $this->author = 'Mykhailo Chernykh';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Translation Importer Pro');
        $this->description = $this->l('Upload and import translation ZIP archives for Theme or Core.');
    }

    public function install()
    {
        return parent::install();
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitImportTranslations')) {
            $output .= $this->processUpload();
        }

        return $output . $this->renderForm();
    }

    public function processUpload()
    {
        if (!isset($_FILES['import_zip']) || $_FILES['import_zip']['error'] != UPLOAD_ERR_OK) {
            return $this->displayError($this->l('Please upload a valid ZIP file.'));
        }

        $target_type = Tools::getValue('target_type'); // theme, core, auto
        $iso_code = Tools::getValue('iso_code'); // it-IT
        
        $zip_file = $_FILES['import_zip']['tmp_name'];
        $zip = new ZipArchive;
        
        if ($zip->open($zip_file) === TRUE) {
            $timestamp = date('Y-m-d_H-i-s');
            $tmp_dir = _PS_MODULE_DIR_ . $this->name . '/tmp/' . $timestamp . '/';
            
            if (!mkdir($tmp_dir, 0777, true)) {
                 $zip->close();
                 return $this->displayError($this->l('Could not create temporary directory.'));
            }
            
            $zip->extractTo($tmp_dir);
            $zip->close();
            
            // Process extracted files with backup
            $log = $this->distributeFiles($tmp_dir, $target_type, $iso_code, $timestamp);
            
            // Cleanup
            $this->recursiveRemoveDir($tmp_dir);
            
            // Clear Cache
            Tools::clearSmartyCache();
            Tools::clearXMLCache();
            Media::clearCache();
            Tools::generateIndex();
            
            return $log;
        } else {
            return $this->displayError($this->l('Failed to open ZIP file.'));
        }
    }

    private function distributeFiles($source_dir, $type, $iso_code, $timestamp)
    {
        $theme_name = Context::getContext()->shop->theme->getName();
        $backup_root = _PS_MODULE_DIR_ . $this->name . '/backups/' . $timestamp . '/';
        $log = '';
        $files = $this->getDirContents($source_dir);
        $count = 0;
        $backup_count = 0;

        foreach ($files as $file) {
            $filename = basename($file);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            
            if ($ext !== 'xlf' && $ext !== 'php' && $ext !== 'tpl') {
                continue; // Skip non-translation files usually
            }

            $destination = '';

            // Logic to determine destination
            if ($type === 'theme') {
                // Force copy to theme folder
                $destination = _PS_ROOT_DIR_ . '/themes/' . $theme_name . '/translations/' . $iso_code . '/';
            } elseif ($type === 'core') {
                // Force copy to core folder
                $destination = _PS_ROOT_DIR_ . '/app/Resources/translations/' . $iso_code . '/';
            } else {
                // Auto-detect based on folder structure in ZIP or Filename
                if (strpos($file, 'Theme') !== false || strpos($filename, 'Shop') === 0) {
                     $destination = _PS_ROOT_DIR_ . '/themes/' . $theme_name . '/translations/' . $iso_code . '/';
                } elseif (strpos($file, 'prestashop') !== false || strpos($filename, 'Admin') === 0) {
                     $destination = _PS_ROOT_DIR_ . '/app/Resources/translations/' . $iso_code . '/';
                } else {
                    // Default fallback
                    $destination = _PS_ROOT_DIR_ . '/themes/' . $theme_name . '/translations/' . $iso_code . '/';
                }
            }

            if ($destination) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0755, true);
                }
                
                $target_file = $destination . $filename;
                
                // Backup Logic
                if (file_exists($target_file)) {
                    if (!is_dir($backup_root)) {
                        mkdir($backup_root, 0755, true);
                    }
                    if (copy($target_file, $backup_root . $filename)) {
                        $backup_count++;
                    }
                }
                
                if (copy($file, $target_file)) {
                    $count++;
                } else {
                    $log .= $this->displayError("Failed to copy $filename");
                }
            }
        }
        
        $msg = sprintf($this->l('Imported %d files successfully to %s.'), $count, $type);
        if ($backup_count > 0) {
            $msg .= '<br>' . sprintf($this->l('Backed up %d existing files to %s'), $backup_count, 'modules/' . $this->name . '/backups/' . $timestamp . '/');
        }
        
        return $log . $this->displayConfirmation($msg);
    }

    public function renderForm()
    {
        $languages = Language::getLanguages(false);
        $lang_options = [];
        foreach ($languages as $lang) {
            $lang_options[] = [
                'id' => $lang['locale'], // Use locale like it-IT
                'name' => $lang['name'] . ' (' . $lang['locale'] . ')'
            ];
        }

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Import Translation Archive'),
                    'icon' => 'icon-upload',
                ],
                'input' => [
                    [
                        'type' => 'file',
                        'label' => $this->l('ZIP Archive'),
                        'name' => 'import_zip',
                        'desc' => $this->l('Upload a ZIP file containing .xlf files. Structure inside ZIP does not matter much if "Theme" or "Core" is selected, all .xlf files will be flattened into the destination.'),
                        'required' => true,
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Target Destination'),
                        'name' => 'target_type',
                        'options' => [
                            'query' => [
                                ['id' => 'auto', 'name' => $this->l('Auto-detect (Based on filename/folder)')],
                                ['id' => 'theme', 'name' => $this->l('Current Theme (Shop*)')],
                                ['id' => 'core', 'name' => $this->l('Core / Backoffice (Admin*)')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Target Language'),
                        'name' => 'iso_code',
                        'options' => [
                            'query' => $lang_options,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Files will be copied into the folder of this language (e.g. it-IT).'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Upload and Import'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitImportTranslations';
        
        return $helper->generateForm([$fields_form]);
    }

    private function getDirContents($dir, &$results = array()) {
        $files = scandir($dir);
        foreach ($files as $key => $value) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if (!is_dir($path)) {
                $results[] = $path;
            } else if ($value != "." && $value != "..") {
                $this->getDirContents($path, $results);
            }
        }
        return $results;
    }

    private function recursiveRemoveDir($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object))
                        $this->recursiveRemoveDir($dir . "/" . $object);
                    else
                        unlink($dir . "/" . $object);
                }
            }
            rmdir($dir);
        }
    }
}
