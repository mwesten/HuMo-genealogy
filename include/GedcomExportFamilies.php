<?php

/**
 * GedcomExportFamilies class
 * 
 * Handles GEDCOM export of family records
 */

namespace Genealogy\Include;

use PDO;

class GedcomExportFamilies extends GedcomExportFunctions
{
    private $persids = array();
    private $famsids = array();

    public function __construct($dbh, $db_functions, $tree_id, $gedcom_version = '551', $gedcom_sources = '')
    {
        $this->dbh = $dbh;
        $this->db_functions = $db_functions;
        $this->tree_id = $tree_id;
        $this->gedcom_version = $gedcom_version;
        $this->gedcom_sources = $gedcom_sources;
    }

    /**
     * Set arrays for partial tree export
     */
    public function setExportArrays($persids, $famsids): void
    {
        $this->persids = $persids;
        $this->famsids = $famsids;
    }

    /**
     * Export all families to GEDCOM file
     * 
     * @param resource $fh File handle
     * @param array $export Export configuration
     * @param string $gedcom_texts Include texts yes/no
     */
    public function exportFamilies($fh, $export, $gedcom_texts): void
    {
        // *** To reduce use of memory, first read fam_id only ***
        $families_qry = $this->dbh->query("SELECT fam_id FROM humo_families WHERE fam_tree_id='" . $this->tree_id . "'");

        while ($families = $families_qry->fetch(PDO::FETCH_OBJ)) {
            // *** Now read all family items ***
            $family = $this->db_functions->get_family_with_id($families->fam_id);

            if ($export["part_tree"] == 'part' && !in_array($family->fam_gedcomnumber, $this->famsids)) {
                continue;
            }

            // 0 @F1@ FAM
            $this->buffer = '0 @' . $family->fam_gedcomnumber . "@ FAM\r\n";

            if (isset($_POST['gedcom_status']) && $_POST['gedcom_status'] == 'yes') {
                echo $family->fam_gedcomnumber . ' ';
            }

            // *** Export partners ***
            $this->exportFamilyPartners($family, $export);

            // *** Export living together ***
            $this->exportFamilyLivingTogether($family, $gedcom_texts);

            // *** Export marriage notices and marriages ***
            if ($_POST['export_type'] == 'normal') {
                $this->exportFamilyMarriageNotices($family, $gedcom_texts);
            }
            $this->exportFamilyMarriage($family, $gedcom_texts);
            if ($_POST['export_type'] == 'normal') {
                $this->exportFamilyMarriageReligious($family, $gedcom_texts);
            }

            // *** Export divorce ***
            if ($_POST['export_type'] == 'normal') {
                $this->exportFamilyDivorce($family, $gedcom_texts);
            }

            // *** Export children ***
            $this->exportFamilyChildren($family, $export);

            // *** Export additional family data ***
            if ($_POST['export_type'] == 'normal') {
                $this->exportFamilyAdditionalData($family, $gedcom_texts);
            }

            // *** Write family data ***
            $this->decode();
            fwrite($fh, $this->buffer);

            // *** Update processed lines ***
            $this->record_nr++;
            $this->perc = $this->update_bootstrap_bar();
        }
    }

    private function exportFamilyPartners($family, $export): void
    {
        if ($family->partner1_gedcomnumber) {
            if ($export["part_tree"] == 'part' && !in_array($family->partner1_gedcomnumber, $this->persids)) {
                // skip if not included
            } else {
                $this->writeLine(1, 'HUSB', '@' . $family->partner1_gedcomnumber . '@');
            }
        }

        if ($family->partner2_gedcomnumber) {
            if ($export["part_tree"] == 'part' && !in_array($family->partner2_gedcomnumber, $this->persids)) {
                // skip if not included
            } else {
                $this->writeLine(1, 'WIFE', '@' . $family->partner2_gedcomnumber . '@');
            }
        }
    }

    private function exportFamilyLivingTogether($family, $gedcom_texts): void
    {
        // *** Pro-gen & HuMo-genealogy: Living together ***
        if ($family->fam_relation_date || $family->fam_relation_place || $family->fam_relation_text) {
            $this->writeLine(1, '_LIV');

            if ($family->fam_relation_date) {
                $this->writeLine(2, 'DATE', $this->process_date($family->fam_relation_date));
            }

            if ($family->fam_relation_place) {
                $this->buffer .= $this->process_place($family->fam_relation_place, 2);
            }

            if ($this->gedcom_sources == 'yes') {
                $this->sources_export('family', 'fam_relation_source', $family->fam_gedcomnumber, 2);
            }

            if ($gedcom_texts == 'yes' && $family->fam_relation_text) {
                $this->writeNote(2, $family->fam_relation_text);
            }
        }
    }

    private function exportFamilyMarriageNotices($family, $gedcom_texts): void
    {
        // *** Marriage notice (civil) ***
        if ($family->fam_marr_notice_date || $family->fam_marr_notice_place || $family->fam_marr_notice_text) {
            $this->writeLine(1, 'MARB');
            $this->writeLine(2, 'TYPE', 'civil');

            if ($family->fam_marr_notice_date) {
                $this->writeLine(2, 'DATE', $this->process_date($family->fam_marr_notice_date));
                if (isset($family->fam_marr_notice_date_hebnight) && $family->fam_marr_notice_date_hebnight == 'y') {
                    $this->writeLine(2, '_HNIT', 'y');
                }
            }

            if ($family->fam_marr_notice_place) {
                $this->buffer .= $this->process_place($family->fam_marr_notice_place, 2);
            }

            if ($this->gedcom_sources == 'yes') {
                $this->sources_export('family', 'fam_marr_notice_source', $family->fam_gedcomnumber, 2);
            }

            if ($gedcom_texts == 'yes' && $family->fam_marr_notice_text) {
                $this->writeNote(2, $family->fam_marr_notice_text);
            }
        }

        // *** Marriage notice (religious) ***
        if ($family->fam_marr_church_notice_date || $family->fam_marr_church_notice_place || $family->fam_marr_church_notice_text) {
            $this->writeLine(1, 'MARB');
            $this->writeLine(2, 'TYPE', 'religious');

            if ($family->fam_marr_church_notice_date) {
                $this->writeLine(2, 'DATE', $this->process_date($family->fam_marr_church_notice_date));
                if (isset($family->fam_marr_church_notice_date_hebnight) && $family->fam_marr_church_notice_date_hebnight == 'y') {
                    $this->writeLine(2, '_HNIT', 'y');
                }
            }

            if ($family->fam_marr_church_notice_place) {
                $this->buffer .= $this->process_place($family->fam_marr_church_notice_place, 2);
            }

            if ($this->gedcom_sources == 'yes') {
                $this->sources_export('family', 'fam_marr_church_notice_source', $family->fam_gedcomnumber, 2);
            }

            if ($gedcom_texts == 'yes' && $family->fam_marr_church_notice_text) {
                $this->writeNote(2, $family->fam_marr_church_notice_text);
            }
        }
    }

    private function exportFamilyMarriage($family, $gedcom_texts): void
    {
        // *** Marriage ***
        if ($family->fam_marr_date || $family->fam_marr_place || $family->fam_marr_text) {
            $this->writeLine(1, 'MARR');

            /**
             * Example:
             * 1 MARR
             * 2 TYPE partners 
             *
             * living together
             * living apart together
             * intentionally unmarried mother
             * homosexual
             * non-marital
             * extramarital
             * partners
             * registered
             * unknown
             */
            if ($_POST['export_type'] == 'normal' && !empty($family->fam_kind)) {
                $this->writeLine(2, 'TYPE', $family->fam_kind);
            }

            if ($family->fam_marr_date) {
                $this->writeLine(2, 'DATE', $this->process_date($family->fam_marr_date));
                if (isset($family->fam_marr_date_hebnight) && $family->fam_marr_date_hebnight == 'y') {
                    $this->writeLine(2, '_HNIT', 'y');
                }
            }

            if ($family->fam_marr_place) {
                $this->buffer .= $this->process_place($family->fam_marr_place, 2);
            }

            if ($_POST['export_type'] == 'normal') {
                if ($this->gedcom_sources == 'yes') {
                    $this->sources_export('family', 'fam_marr_source', $family->fam_gedcomnumber, 2);
                }

                if ($family->partner1_age) {
                    $this->writeLine(2, 'HUSB');
                    $this->writeLine(3, 'AGE', $family->partner1_age);
                }

                if ($family->partner2_age) {
                    $this->writeLine(2, 'WIFE');
                    $this->writeLine(3, 'AGE', $family->partner2_age);
                }

                if ($gedcom_texts == 'yes' && $family->fam_marr_text) {
                    $this->writeNote(2, $family->fam_marr_text);
                }

                $this->buffer .= $this->export_witnesses('MARR', $family->fam_gedcomnumber, 'ASSO');
            }
        }
    }

    private function exportFamilyMarriageReligious($family, $gedcom_texts): void
    {
        // *** Marriage religious ***
        if ($family->fam_marr_church_date || $family->fam_marr_church_place || $family->fam_marr_church_text) {
            $this->writeLine(1, 'MARR');
            $this->writeLine(2, 'TYPE', 'religious');

            if ($family->fam_marr_church_date) {
                $this->writeLine(2, 'DATE', $this->process_date($family->fam_marr_church_date));
                if (isset($family->fam_marr_church_date_hebnight) && $family->fam_marr_church_date_hebnight == 'y') {
                    $this->writeLine(2, '_HNIT', 'y');
                }
            }

            if ($family->fam_marr_church_place) {
                $this->buffer .= $this->process_place($family->fam_marr_church_place, 2);
            }

            if ($this->gedcom_sources == 'yes') {
                $this->sources_export('family', 'fam_marr_church_source', $family->fam_gedcomnumber, 2);
            }

            if ($gedcom_texts == 'yes' && $family->fam_marr_church_text) {
                $this->writeNote(2, $family->fam_marr_church_text);
            }

            $this->buffer .= $this->export_witnesses('MARR_REL', $family->fam_gedcomnumber, 'ASSO');
        }
    }

    private function exportFamilyDivorce($family, $gedcom_texts): void
    {
        // *** Divorced ***
        if ($family->fam_div_date || $family->fam_div_place || $family->fam_div_text) {
            $this->writeLine(1, 'DIV');

            if ($family->fam_div_date) {
                $this->writeLine(2, 'DATE', $this->process_date($family->fam_div_date));
            }

            if ($family->fam_div_place) {
                $this->buffer .= $this->process_place($family->fam_div_place, 2);
            }

            if ($this->gedcom_sources == 'yes') {
                $this->sources_export('family', 'fam_div_source', $family->fam_gedcomnumber, 2);
            }

            if ($gedcom_texts == 'yes' && $family->fam_div_text && $family->fam_div_text != 'DIVORCE') {
                $this->buffer .= '2 NOTE ' . $this->process_text(3, $family->fam_div_text);
            }
        }
    }

    private function exportFamilyChildren($family, $export): void
    {
        $children = $this->db_functions->get_children($family->fam_id);
        if ($children) {
            foreach ($children as $child) {
                if ($export["part_tree"] == 'part' && !in_array($child->person_gedcomnumber, $this->persids)) {
                    continue;
                }
                $this->writeLine(1, 'CHIL', '@' . $child->person_gedcomnumber . '@');
            }
        }
    }

    private function exportFamilyAdditionalData($family, $gedcom_texts): void
    {
        // *** Family source ***
        if ($this->gedcom_sources == 'yes') {
            $this->sources_export('family', 'family_source', $family->fam_gedcomnumber, 1);
        }

        // *** Addresses (shared addresses are no valid GEDCOM 5.5.1) ***
        $this->addresses_export('family', 'family_address', $family->fam_gedcomnumber);

        // *** Family pictures ***
        $pictures = $this->db_functions->get_events_connect('family', $family->fam_gedcomnumber, 'picture');
        foreach ($pictures as $picture) {
            $this->writeLine(1, 'OBJE');
            $this->writeLine(2, 'FORM', 'jpg');
            $this->writeLine(2, 'FILE', $picture->event_event);

            if ($picture->event_date) {
                $this->writeLine(2, 'DATE', $this->process_date($picture->event_date));
            }

            if ($gedcom_texts == 'yes' && $picture->event_text) {
                $this->buffer .= '2 NOTE ' . $this->process_text(3, $picture->event_text);
            }

            if ($this->gedcom_sources == 'yes') {
                $this->sources_export('family', 'fam_event_source', $picture->event_id, 2);
            }
        }

        // *** Family Note ***
        if ($gedcom_texts == 'yes' && $family->fam_text) {
            $this->writeNote(1, $family->fam_text);
            $this->sources_export('family', 'fam_text_source', $family->fam_gedcomnumber, 2);
        }

        // *** Family events ***
        $this->exportFamilyEvents($family);

        // *** Datetime new and changed ***
        $this->buffer .= $this->process_datetime('new', $family->fam_new_datetime, $family->fam_new_user_id);
        $this->buffer .= $this->process_datetime('changed', $family->fam_changed_datetime, $family->fam_changed_user_id);
    }

    private function exportFamilyEvents($family): void
    {
        $events = $this->db_functions->get_events_connect('family', $family->fam_gedcomnumber, 'event');

        $eventMapping = [
            'ANUL' => '1 ANUL',
            'CENS' => '1 CENS',
            'DIVF' => '1 DIVF',
            'ENGA' => '1 ENGA',
            'EVEN' => '1 EVEN',
            'MARC' => '1 MARC',
            'MARL' => '1 MARL',
            'MARS' => '1 MARS',
            'SLGS' => '1 SLGS'
        ];

        foreach ($events as $event) {
            if (array_key_exists($event->event_gedcom, $eventMapping)) {
                $this->buffer .= $eventMapping[$event->event_gedcom];
                if ($event->event_event) {
                    $this->buffer .= ' ' . $event->event_event;
                }
                $this->buffer .= "\r\n";

                if ($event->event_text) {
                    $this->buffer .= '2 NOTE ' . $this->process_text(3, $event->event_text);
                }

                if ($event->event_date) {
                    $this->writeLine(2, 'DATE', $this->process_date($event->event_date));
                }

                if ($event->event_place) {
                    $this->writeLine(2, 'PLAC', $event->event_place);
                }
            }
        }
    }

    public function getProgressTracking(): array
    {
        return [
            'record_nr' => $this->record_nr,
            'step' => $this->step,
            'devider' => $this->devider,
            'perc' => $this->perc,
            'noteids' => $this->noteids
        ];
    }

    public function setProgressTracking($record_nr, $step, $devider, $perc): void
    {
        $this->record_nr = $record_nr;
        $this->step = $step;
        $this->devider = $devider;
        $this->perc = $perc;
    }
}
