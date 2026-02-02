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
        } elseif (Tools::isSubmit('submitExportTranslations')) {
            $this->processExport();
        }

        return $output . $this->renderForm();
    }

    public function processExport()
    {
        $iso_code = Tools::getValue('export_iso_code', 'it-IT');
        $type = Tools::getValue('export_type', 'theme');
        $theme_name = Context::getContext()->shop->theme->getName();
        
        $files_found = [];
        
        // Define sources based on type
        if ($type === 'theme') {
            $s1 = _PS_ROOT_DIR_ . '/themes/' . $theme_name . '/translations/' . $iso_code . '/';
            if (is_dir($s1)) {
                $files = glob($s1 . 'Modules*.xlf');
                if ($files) $files_found = array_merge($files_found, $files);
            }
        } elseif ($type === 'core') {
            // Modern path
            $s1 = _PS_ROOT_DIR_ . '/translations/' . $iso_code . '/';
            if (is_dir($s1)) {
                $files = glob($s1 . 'Modules*.xlf');
                if ($files) $files_found = array_merge($files_found, $files);
            }
            // Legacy path
            $s2 = _PS_ROOT_DIR_ . '/app/Resources/translations/' . $iso_code . '/';
            if (is_dir($s2)) {
                $files = glob($s2 . 'Modules*.xlf');
                if ($files) $files_found = array_merge($files_found, $files);
            }
        }
        
        if (empty($files_found)) {
             $output = $this->displayError($this->l('No translation files found for this selection.'));
             return $output;
        }

        $zip_filename = 'translations_export_' . $type . '_' . $iso_code . '_' . date('Y-m-d_H-i-s') . '.zip';
        $zip_path = _PS_MODULE_DIR_ . $this->name . '/tmp/' . $zip_filename;
        
        if (!is_dir(dirname($zip_path))) {
            mkdir(dirname($zip_path), 0777, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($files_found as $file) {
                // Keep the filename flat in the zip for easy re-import
                $zip->addFile($file, basename($file));
            }
            $zip->close();
            
            // Trigger Download
            if (file_exists($zip_path)) {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
                header('Content-Length: ' . filesize($zip_path));
                // Clean output buffer to ensure valid zip
                if (ob_get_level()) ob_end_clean();
                readfile($zip_path);
                unlink($zip_path); // Delete after download
                exit;
            }
        } else {
            return $this->displayError($this->l('Could not create ZIP file.'));
        }
    }

    public function processUpload()
    {
        if (!isset($_FILES['import_zip']) || $_FILES['import_zip']['error'] != UPLOAD_ERR_OK) {
            return $this->displayError($this->l('Please upload a valid ZIP file.'));
        }

        $target_type = Tools::getValue('target_type', 'auto'); // theme, core, auto (default: auto)
        $iso_code = Tools::getValue('iso_code', 'it-IT'); // it-IT (default)
        
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
                // PrestaShop 8/9+ vs 1.7
                if (is_dir(_PS_ROOT_DIR_ . '/translations/')) {
                    $destination = _PS_ROOT_DIR_ . '/translations/' . $iso_code . '/';
                } else {
                    $destination = _PS_ROOT_DIR_ . '/app/Resources/translations/' . $iso_code . '/';
                }
            } else {
                // Auto-detect based on folder structure in ZIP or Filename
                if (strpos($file, 'Theme') !== false || strpos($filename, 'Shop') === 0) {
                     $destination = _PS_ROOT_DIR_ . '/themes/' . $theme_name . '/translations/' . $iso_code . '/';
                } elseif (strpos($file, 'prestashop') !== false || strpos($filename, 'Admin') === 0) {
                     // Auto-detect core path
                     if (is_dir(_PS_ROOT_DIR_ . '/translations/')) {
                         $destination = _PS_ROOT_DIR_ . '/translations/' . $iso_code . '/';
                     } else {
                         $destination = _PS_ROOT_DIR_ . '/app/Resources/translations/' . $iso_code . '/';
                     }
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

        $fields_form_export = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Export Module Translations (XLF)'),
                    'icon' => 'icon-download',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Export Source'),
                        'name' => 'export_type',
                        'options' => [
                            'query' => [
                                ['id' => 'theme', 'name' => $this->l('Theme Modules (themes/YOUR_THEME/translations/...)')],
                                ['id' => 'core', 'name' => $this->l('Core / Installed Modules (translations/...)')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Language'),
                        'name' => 'export_iso_code',
                        'options' => [
                            'query' => $lang_options,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Export to ZIP'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitExportTranslations',
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
        $helper->fields_value = $this->getConfigFieldsValues();
        
        // Render Import Form
        $out = $helper->generateForm([$fields_form]);
        
        // Setup for Export Form
        $helper->submit_action = 'submitExportTranslations'; 
        // Note: fields_value are shared, getConfigFieldsValues includes default for both
        $out .= $helper->generateForm([$fields_form_export]);
        
        return $out;
    }

    public function getConfigFieldsValues()
    {
        return [
            'import_zip' => '',
            'target_type' => Tools::getValue('target_type', 'auto'),
            'iso_code' => Tools::getValue('iso_code', 'it-IT'),
            'export_type' => Tools::getValue('export_type', 'theme'),
            'export_iso_code' => Tools::getValue('export_iso_code', 'it-IT'),
        ];
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
