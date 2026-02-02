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
        } elseif (Tools::isSubmit('submitCloneContent')) {
            $output .= $this->processCloneContent();
        } elseif (Tools::isSubmit('submitExportDB')) {
            $this->processExportDB();
        } elseif (Tools::isSubmit('submitImportDB')) {
            $output .= $this->processImportDB();
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
                $files = glob($s1 . '*.xlf');
                if ($files) $files_found = array_merge($files_found, $files);
            }
        } elseif ($type === 'core') {
            // Modern path
            $s1 = _PS_ROOT_DIR_ . '/translations/' . $iso_code . '/';
            if (is_dir($s1)) {
                $files = glob($s1 . '*.xlf');
                if ($files) $files_found = array_merge($files_found, $files);
            }
            // Legacy path
            $s2 = _PS_ROOT_DIR_ . '/app/Resources/translations/' . $iso_code . '/';
            if (is_dir($s2)) {
                $files = glob($s2 . '*.xlf');
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
        $files_log = []; // Array to store detailed log

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
                    $files_log[] = "Moved <b>$filename</b> to <i>" . str_replace(_PS_ROOT_DIR_, '', $destination) . "</i>";
                } else {
                    $log .= $this->displayError("Failed to copy $filename");
                }
            }
        }
        
        $msg = sprintf($this->l('Imported %d files successfully to %s.'), $count, $type);
        if ($backup_count > 0) {
            $msg .= '<br>' . sprintf($this->l('Backed up %d existing files to %s'), $backup_count, 'modules/' . $this->name . '/backups/' . $timestamp . '/');
        }

        // Add Log Details
        if (!empty($files_log)) {
            $msg .= '<div class="alert alert-info" style="max-height: 200px; overflow-y: auto;">';
            $msg .= '<h4>' . $this->l('Detailed Log:') . '</h4><ul style="list-style-type: none; padding-left: 0;">';
            foreach ($files_log as $l) {
                $msg .= '<li>' . $l . '</li>';
            }
            $msg .= '</ul></div>';
        }
        
        return $log . $this->displayConfirmation($msg);
    }

    public function processCloneContent()
    {
        $from_lang_id = (int)Tools::getValue('clone_from_lang');
        $to_lang_id = (int)Tools::getValue('clone_to_lang');

        if ($from_lang_id == $to_lang_id) {
             return $this->displayError($this->l('Source and Target languages must be different.'));
        }

        $tables = Db::getInstance()->executeS('SHOW TABLES LIKE "' . _DB_PREFIX_ . '%_lang"');
        $count_tables = 0;
        $log = '';

        foreach ($tables as $t) {
            $table_name = current($t); 
            
            // Get Columns
            $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $table_name . '`');
            
            $col_names = [];
            $select_parts = [];
            $has_id_lang = false;
            
            foreach ($columns as $c) {
                $field = $c['Field'];
                $col_names[] = '`' . $field . '`';
                
                if ($field == 'id_lang') {
                    $has_id_lang = true;
                    $select_parts[] = $to_lang_id; 
                } else {
                    $select_parts[] = '`' . $field . '`';
                }
            }
            
            if (!$has_id_lang) continue; 

            // 1. Delete Target Data (Clean State)
            Db::getInstance()->execute('DELETE FROM `' . $table_name . '` WHERE `id_lang` = ' . $to_lang_id);

            // 2. Insert Source Data as Target
            // Use INSERT IGNORE to be safe against some constraints, though DELETE beforehand handles most
            $sql = 'INSERT INTO `' . $table_name . '` (' . implode(', ', $col_names) . ') 
                    SELECT ' . implode(', ', $select_parts) . ' FROM `' . $table_name . '` 
                    WHERE `id_lang` = ' . $from_lang_id;
            
            try {
                if (Db::getInstance()->execute($sql)) {
                    $count_tables++;
                }
            } catch (Exception $e) {
                $log .= '<br/>Skipped ' . $table_name . ': ' . $e->getMessage();
            }
        }

        return $this->displayConfirmation($this->l("Successfully cloned DB Content for $count_tables tables information.")) . 
               ($log ? '<div class="alert alert-warning">Warnings (some tables like order_status might be skipped safely): ' . $log . '</div>' : '');
    }

    public function processExportDB()
    {
        $id_lang = (int)Tools::getValue('db_export_lang');
        if (!$id_lang) return;

        // Force check only relevant tables to avoid timeout or garbage
        $relevant_tables = [
            'ps_product_lang', 'ps_category_lang', 'ps_cms_lang',
            'ps_manufacturer_lang', 'ps_supplier_lang', 'ps_homeslider_slides_lang',
            'ps_attribute_group_lang', 'ps_attribute_lang', 'ps_feature_lang',
            'ps_feature_value_lang', 'ps_meta_lang'
        ];
        
        // Auto-detect other _lang tables
        $all_tables = Db::getInstance()->executeS("SHOW TABLES LIKE '" . _DB_PREFIX_ . "%_lang'");
        foreach ($all_tables as $t) {
            $tbl = current($t);
             // Simple heuristic: not in ignore list (log/stats)
            if (strpos($tbl, 'log') === false && strpos($tbl, 'stats') === false && strpos($tbl, 'connections') === false) {
                 $relevant_tables[] = $tbl;
            }
        }
        $relevant_tables = array_unique($relevant_tables);
        
        $export_data = [];

        foreach ($relevant_tables as $table) {
            // Check if table exists
            $ch = Db::getInstance()->executeS("SHOW TABLES LIKE '$table'");
            if (empty($ch)) continue;

            // Get Primary Key
            $columns = Db::getInstance()->executeS("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
            $pks = [];
            foreach ($columns as $c) {
                $pks[] = $c['Column_name'];
            }

            // Get Data
            $rows = Db::getInstance()->executeS("SELECT * FROM `$table` WHERE id_lang = $id_lang");
            
            if (!empty($rows)) {
                $export_data[$table] = [
                    'pks' => $pks,
                    'rows' => $rows
                ];
            }
        }

        $json = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // Download
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="db_content_lang_' . $id_lang . '.json"');
        echo $json;
        exit;
    }

    public function processImportDB()
    {
        if (isset($_FILES['db_import_file']) && $_FILES['db_import_file']['error'] == 0) {
            $json = file_get_contents($_FILES['db_import_file']['tmp_name']);
            $data = json_decode($json, true);

            if (!$data) {
                return $this->displayError($this->l('Invalid JSON file.'));
            }

            $count_tables = 0;
            $count_rows = 0;
            $db = Db::getInstance();

            foreach ($data as $table => $info) {
                $pks = $info['pks']; 
                $rows = $info['rows'];
                
                // Security check
                $table = bqSQL($table);
                
                foreach ($rows as $row) {
                    $where = [];
                    $updates = [];
                    
                    foreach ($row as $col => $val) {
                        if (in_array($col, $pks)) {
                            $where[] = "`" . bqSQL($col) . "` = '" . pSQL($val) . "'";
                        } else {
                            // Update content
                            $updates[] = "`" . bqSQL($col) . "` = '" . pSQL($val, true) . "'";
                        }
                    }
                    
                    if (!empty($updates) && !empty($where)) {
                        $sql = "UPDATE `$table` SET " . implode(', ', $updates) . " WHERE " . implode(' AND ', $where);
                        if ($db->execute($sql)) {
                            $count_rows++;
                        }
                    }
                }
                $count_tables++;
            }
            
            return $this->displayConfirmation($this->l("Updated DB: Processed $count_tables tables and $count_rows rows."));
        }
        return $this->displayError($this->l('No file uploaded.'));
    }

    public function renderForm()
    {
        $languages = Language::getLanguages(false);
        $lang_options = [];
        $lang_options_ids = [];
        foreach ($languages as $lang) {
            $lang_options[] = [
                'id' => $lang['locale'], // Use locale like it-IT
                'name' => $lang['name'] . ' (' . $lang['locale'] . ')'
            ];
            $lang_options_ids[] = [
                'id' => $lang['id_lang'], 
                'name' => $lang['name'] . ' (ID: ' . $lang['id_lang'] . ')' 
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

        // 3. Clone DB Form
        $fields_form_clone = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Clone Language Content (DB)'),
                    'icon' => 'icon-copy',
                ],
                'description' => $this->l('<b>DANGER ZONE:</b> This tool will CLONE database content (products, categories, cms, sliders) from one language to another.<br/>It iterates through all tables ending in <code>_lang</code>.<br/><span style="color:red">The Target Language content will be ERASED before copying.</span>'),
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Source Language (COPY FROM)'),
                        'name' => 'clone_from_lang',
                        'options' => [
                            'query' => $lang_options_ids,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Target Language (OVERWRITE TO)'),
                        'name' => 'clone_to_lang',
                        'options' => [
                            'query' => $lang_options_ids,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('CLONE DATABASE CONTENT'),
                    'class' => 'btn btn-danger pull-right',
                    'name' => 'submitCloneContent',
                    'onclick' => "return confirm('Are you sure? This will OVERWRITE data.');"
                ],
            ],
        ];

        // Form 4: AI DB Translation (JSON)
        $fields_form_db = [
            'form' => [
                'legend' => [
                    'title' => $this->l('AI DB Auto-Translate (JSON)'),
                    'icon' => 'icon-code',
                ],
                'description' => $this->l('1. Export Source Language JSON.<br/>2. Translate JSON externally (Python/AI).<br/>3. Import Result JSON (English).'),
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Export Source Language'),
                        'name' => 'db_export_lang',
                        'options' => [
                            'query' => $lang_options_ids,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'file',
                        'label' => $this->l('Import Translated JSON'),
                        'name' => 'db_import_file',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Import JSON'),
                    'class' => 'btn btn-success pull-right',
                    'name' => 'submitImportDB',
                ],
                // Add a custom button for Export logic via hack or separate form? HelperForm supports one submit.
                // We will add a second submit button "Export" by injecting HTML or using separate form logic.
                // PrestaShop HelperForm usually expects one submit. Let's make Export a separate small form or button in the same form.
                // Simpler: Just render 4th form for Export and 5th for Import? Or combine with actions.
                // Let's use 'submit' for Import, and add a 'desc' link for Export? No.
                // Let's try adding a second button in 'buttons' array if PS version supports, or just rely on getContent checks.
            ],
        ];
        
        // Export DB Form
        $fields_form_db_export = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Step 1: Export DB JSON'),
                    'icon' => 'icon-cloud-download',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Source Language'),
                        'name' => 'db_export_lang',
                        'options' => [
                            'query' => $lang_options_ids,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Download JSON'),
                    'class' => 'btn btn-default',
                    'name' => 'submitExportDB',
                ]
            ]
        ];

        // Import DB Form
        $fields_form_db_import = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Step 2: Import DB JSON'),
                    'icon' => 'icon-cloud-upload',
                ],
                'input' => [
                    [
                        'type' => 'file',
                        'label' => $this->l('Translated JSON File'),
                        'name' => 'db_import_file',
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Import JSON to DB'),
                    'class' => 'btn btn-success',
                    'name' => 'submitImportDB',
                ]
            ]
        ];

        $helper->submit_action = 'submitCloneContent'; 
        $out .= $helper->generateForm([$fields_form_clone]);
        
        // DB Export
        $helper->submit_action = 'submitExportDB';
        $out .= $helper->generateForm([$fields_form_db_export]);

        // DB Import
        $helper->submit_action = 'submitImportDB';
        $out .= $helper->generateForm([$fields_form_db_import]);
        
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
            'clone_from_lang' => Tools::getValue('clone_from_lang', Context::getContext()->language->id),
            'clone_to_lang' => Tools::getValue('clone_to_lang', 0),
            'db_export_lang' => Tools::getValue('db_export_lang', Context::getContext()->language->id),
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
