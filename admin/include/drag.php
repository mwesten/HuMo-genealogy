<?php

/**
 * Use Ajax to order children, media, sources, etc.
 */

session_start();

//if (!defined('ADMIN_PAGE')){
//  exit;
//}

// *** Autoload composer classes ***
require __DIR__ . '/../../vendor/autoload.php';

if (isset($_SESSION['admin_tree_id'])) {
    $ADMIN = TRUE; // *** Override "no database" message for admin ***
    include_once(__DIR__ . "/../../include/db_login.php"); // *** Database login ***

    $drag_kind = $_GET["drag_kind"];

    if ($drag_kind == "relations") {
        $relation_arr = explode(";", $_GET['relstring']);
        $counter = count($relation_arr);
        for ($x = 0; $x < $counter; $x++) {
            if (is_numeric($relation_arr[$x])) {
                $dbh->query("UPDATE humo_relations_persons SET relation_order='" . ($x + 1) . "' WHERE id='" . $relation_arr[$x] . "'");
            }
        }
    }

    if ($drag_kind == "children") {
        $child_arr = explode(";", $_GET['chldstring']);
        $counter = count($child_arr);
        for ($x = 0; $x < $counter; $x++) {
            if (is_numeric($child_arr[$x])) {
                $dbh->query("UPDATE humo_relations_persons SET relation_order='" . ($x + 1) . "' WHERE id='" . $child_arr[$x] . "'");
            }
        }
    }

    if ($drag_kind == "media") {
        $media_arr = explode(";", $_GET['mediastring']);
        $counter = count($media_arr);
        for ($x = 0; $x < $counter; $x++) {
            if (is_numeric($media_arr[$x])) {
                $dbh->query("UPDATE humo_events SET event_order='" . ($x + 1) . "' WHERE event_id='" . $media_arr[$x] . "'");
            }
        }
    }

    if ($drag_kind == "sources") {
        $media_arr = explode(";", $_GET['sourcestring']);
        $counter = count($media_arr);
        for ($x = 0; $x < $counter; $x++) {
            if (is_numeric($media_arr[$x])) {
                $dbh->query("UPDATE humo_connections SET connect_order='" . ($x + 1) . "' WHERE connect_id='" . $media_arr[$x] . "'");
            }
        }
    }

    if ($drag_kind == "addresses") {
        $media_arr = explode(";", $_GET['order']);
        $counter = count($media_arr);
        for ($x = 0; $x < $counter; $x++) {
            if (is_numeric($media_arr[$x])) {
                $dbh->query("UPDATE humo_connections SET connect_order='" . ($x + 1) . "' WHERE connect_id='" . $media_arr[$x] . "'");
            }
        }
    }

    if ($drag_kind == "trees") {
        $media_arr = explode(";", $_GET['order']);
        $counter = count($media_arr);
        for ($x = 0; $x < $counter; $x++) {
            if (is_numeric($media_arr[$x])) {
                $dbh->query("UPDATE humo_trees SET tree_order='" . ($x + 1) . "' WHERE tree_id='" . $media_arr[$x] . "'");
            }
        }
    }

    if ($drag_kind == "homepage_modules") {
        $media_arr = explode(";", $_GET['order']);
        $counter = count($media_arr);
        for ($x = 0; $x < $counter; $x++) {
            if (is_numeric($media_arr[$x])) {
                $dbh->query("UPDATE humo_settings SET setting_order='" . ($x + 1) . "' WHERE setting_id='" . $media_arr[$x] . "'");
            }
        }
    }

    if ($drag_kind == "cms_pages") {
        $media_arr = explode(";", $_GET['order']);
        $counter = count($media_arr);
        for ($x = 0; $x < $counter; $x++) {
            if (is_numeric($media_arr[$x])) {
                $dbh->query("UPDATE humo_cms_pages SET page_order='" . ($x + 1) . "' WHERE page_id='" . $media_arr[$x] . "'");
            }
        }
    }

    if ($drag_kind == "cms_categories") {
        $media_arr = explode(";", $_GET['order']);
        $counter = count($media_arr);
        for ($x = 0; $x < $counter; $x++) {
            if (is_numeric($media_arr[$x])) {
                $dbh->query("UPDATE humo_cms_menu SET menu_order='" . ($x + 1) . "' WHERE menu_id='" . $media_arr[$x] . "'");
            }
        }
    }

    if ($drag_kind == 'events') {
        $media_arr = explode(";", $_GET['order']);
        $counter = count($media_arr);
        for ($x = 0; $x < $counter; $x++) {
            if (is_numeric($media_arr[$x])) {
                $dbh->query("UPDATE humo_events SET event_order='" . ($x + 1) . "' WHERE event_id='" . $media_arr[$x]  . "'");
            }
        }
    }
}
