<?php

namespace Genealogy\Admin\Models;

class GedcomModel
{
    public function get_step()
    {
        $step = '1';
        if (isset($_POST['step']) && is_numeric($_POST['step'])) {
            $step = $_POST['step'];
        }
        if (isset($_GET['step']) && is_numeric($_GET['step'])) {
            $step = $_GET['step'];
        }
        return $step;
    }

    public function get_gedcom_directory()
    {
        $gedcom_directory = "gedcom_files";

        // *** Only needed for Huub's test server ***
        if (@file_exists("../../gedcom-bestanden")) {
            $gedcom_directory = "../../gedcom-bestanden";
        }
        return $gedcom_directory;
    }

    public function upload_gedcom()
    {
        $trees['upload_success'] = '';
        $trees['upload_failed'] = '';

        if (isset($_POST['upload'])) {
            // *** Only needed for Huub's test server ***
            if (file_exists("../../gedcom-bestanden")) {
                $gedcom_directory = "../../gedcom-bestanden";
            } elseif (file_exists("gedcom_files")) {
                $gedcom_directory = "gedcom_files";
            } else {
                $gedcom_directory = ".";
            }

            // *** Only upload .ged or .zip files ***
            if (strtolower(substr($_FILES['upload_file']['name'], -4)) === '.zip' || strtolower(substr($_FILES['upload_file']['name'], -4)) === '.ged') {
                $new_upload = $gedcom_directory . '/' . basename($_FILES['upload_file']['name']);
                // *** Move and check for succesful upload ***
                if (move_uploaded_file($_FILES['upload_file']['tmp_name'], $new_upload)) {
                    $trees['upload_success'] = $new_upload . '<br>' . __('File successfully uploaded.') . '</b>';
                } else {
                    $trees['upload_failed'] = $new_upload . '<br>' . __('Upload has failed.') . '</b>';
                }

                // *** If file is zipped, unzip it ***
                if (strtolower(substr($new_upload, -4)) === '.zip') {
                    $zip = new \ZipArchive;
                    $res = $zip->open($new_upload);
                    if ($res === TRUE) {

                        // *** Only unzip .ged files ***
                        $check_gedcom = true;
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $filename = $zip->getNameIndex($i);
                            if (strtolower(substr($filename, -4)) !== '.ged') {
                                $check_gedcom = false;
                            }
                        }
                        if ($check_gedcom) {
                            $zip->extractTo($gedcom_directory);
                            $zip->close();
                            $trees['upload_success'] .= '<br>Succesfully unzipped file!';
                        }
                    } else {
                        $trees['upload_failed'] .= '<br>Error in unzipping file!';
                    }
                }
            } else {
                $trees['upload_failed'] = __('Upload has failed.');
            }
        }
        return $trees;
    }

    public function remove_gedcom_files($trees)
    {
        $removed_filenames = [];
        if (isset($_POST['remove_gedcom_files2']) && isset($_POST['remove_confirm'])) {
            // *** Remove old GEDCOM files ***
            $dh  = opendir($trees['gedcom_directory']);
            while (false !== ($filename = readdir($dh))) {
                if (strtolower(substr($filename, -3)) === "ged") {
                    if ($_POST['remove_gedcom_files2'] == 'gedcom_files_all') {
                        $filenames[] = $trees['gedcom_directory'] . '/' . $filename;
                    } elseif ($_POST['remove_gedcom_files2'] == 'gedcom_files_1_month') {
                        if (time() - filemtime($trees['gedcom_directory'] . '/' . $filename) >= 60 * 60 * 24 * 30) {
                            // 30 days
                            $filenames[] = $trees['gedcom_directory'] . '/' . $filename;
                        }
                    } elseif ($_POST['remove_gedcom_files2'] == 'gedcom_files_1_year') {
                        if (time() - filemtime($trees['gedcom_directory'] . '/' . $filename) >= 60 * 60 * 24 * 365) {
                            // 365 days
                            $filenames[] = $trees['gedcom_directory'] . '/' . $filename;
                        }
                    }
                }
            }

            // *** Order GEDCOM files by alfabet ***
            if (isset($filenames)) {
                usort($filenames, 'strnatcasecmp');
            }

            $counter = count($filenames);
            for ($i = 0; $i < $counter; $i++) {
                $removed_filenames[] = $filenames[$i];
                unlink($filenames[$i]);
            }
        }
        return $removed_filenames;
    }

    public function update_settings($db_functions)
    {
        $setting_value = 'n';
        if (isset($_POST["add_source"])) {
            $setting_value = 'y';
        }
        $db_functions->update_settings('gedcom_read_add_source', $setting_value);

        $setting_value = 'n';
        if (isset($_POST["reassign_gedcomnumbers"])) {
            $setting_value = 'y';
        }
        $db_functions->update_settings('gedcom_read_reassign_gedcomnumbers', $setting_value);

        $setting_value = 'n';
        if (isset($_POST["order_by_date"])) {
            $setting_value = 'y';
        }
        $db_functions->update_settings('gedcom_read_order_by_date', $setting_value);

        $setting_value = 'n';
        if (isset($_POST["order_by_fams"])) {
            $setting_value = 'y';
        }
        $db_functions->update_settings('gedcom_read_order_by_fams', $setting_value);

        /*
        $setting_value = 'n';
        if (isset($_POST["process_geo_location"])) {
            $setting_value = 'y';
        }
        $db_functions->update_settings('gedcom_read_process_geo_location', $setting_value);
        */

        if (isset($_POST['gedcom_process_pict_path'])) {
            $db_functions->update_settings('gedcom_process_pict_path', $_POST['gedcom_process_pict_path']);
        }

        if (isset($_POST['commit_records'])) {
            $db_functions->update_settings('gedcom_read_commit_records', $_POST['commit_records']);
        }

        if (isset($_POST['time_out']) && is_numeric($_POST['time_out'])) {
            $db_functions->update_settings('gedcom_read_time_out', $_POST['time_out']);
        }
    }

    public function read_gedcom_file()
    {
        // *** Processing time ***
        if ($_SESSION['save_starttime'] == 0) {
            $_SESSION['save_starttime'] = time();
        }
        $_SESSION['save_start_timeout'] = time(); // *** Start controlled time-out ***

    }

    public function is_add_tree(): bool
    {
        $add_tree = false;
        if ($_SESSION['add_tree'] == true) {
            $add_tree = true;
            unset($_SESSION['add_tree']); // we don't want the session variable to persist - can cause problems!
        }
        return $add_tree;
    }

    public function is_reassign($humo_option): bool
    {
        $reassign = false;
        if ($humo_option["gedcom_read_reassign_gedcomnumbers"] == 'y') {
            $reassign = true;
        }
        return $reassign;
    }

    /*
    public function get_check_processed()
    {
        if (isset($_POST['check_processed']) && $_POST['check_processed'] == '1') {
            $check_processed = true;
        } else {
            $check_processed = false;
        }
        return $check_processed;
    }
    */
}
