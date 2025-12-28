<?php

/**
 * GedcomExportSources class
 * 
 * Handles GEDCOM export of sources and repositories
 * Dec. 2025 Extracted from GedcomExport class
 */

namespace Genealogy\Include;

use PDO;

class GedcomExportSources extends GedcomExportFunctions
{
    //private $db_functions;
    //private $tree_id;
    //private $gedcom_sources = '';

    public function __construct($dbh, $db_functions, $tree_id, $gedcom_version = '551', $gedcom_sources = '')
    {
        $this->dbh = $dbh;
        $this->db_functions = $db_functions;
        $this->tree_id = $tree_id;
        $this->gedcom_version = $gedcom_version;
        $this->gedcom_sources = $gedcom_sources;
    }

    /**
     * Export all sources and repositories to GEDCOM file
     * 
     * @param resource $fh File handle
     * @param array $export Export configuration
     * @param string $gedcom_texts Include texts yes/no
     * @param array $persids Person IDs for partial tree export
     * @param array $famsids Family IDs for partial tree export
     */
    public function exportSources($fh, $export, $gedcom_texts, $persids = array(), $famsids = array()): void
    {
        // Build source array for partial tree export
        $source_array = array();
        if ($export["part_tree"] == 'part') {
            $source_array = $this->buildSourceArray($persids, $famsids);
        }

        // *** Export sources ***
        if ($this->gedcom_sources == 'yes') {
            $this->exportSourceRecords($fh, $export, $gedcom_texts, $source_array);
            $this->exportRepositories($fh);
        }
    }

    private function buildSourceArray($persids, $famsids): array
    {
        $source_array = array();

        // Find all sources referred to by persons (I233) or families (F233)
        $qry = $this->dbh->query("SELECT connect_connect_id, connect_source_id FROM humo_connections
            WHERE connect_tree_id='" . $this->tree_id . "' AND connect_source_id != ''");
        while ($qryDb = $qry->fetch(PDO::FETCH_OBJ)) {
            if (in_array($qryDb->connect_connect_id, $persids) || in_array($qryDb->connect_connect_id, $famsids)) {
                $source_array[] = $qryDb->connect_source_id;
            }
        }

        // Find sources referred to by addresses via multi-step procedure
        $address_connect_qry = $this->dbh->query("SELECT connect_connect_id, connect_item_id
            FROM humo_connections WHERE connect_tree_id='" . $this->tree_id . "' AND connect_sub_kind LIKE '%_address'");
        $resi_array = array();
        while ($address_connect_qryDb = $address_connect_qry->fetch(PDO::FETCH_OBJ)) {
            if (in_array($address_connect_qryDb->connect_connect_id, $persids) || in_array($address_connect_qryDb->connect_connect_id, $famsids)) {
                $resi_array[] = $address_connect_qryDb->connect_item_id;
            }
        }

        // Get address IDs from the previously found R numbers
        $address_address_qry = $this->dbh->query("SELECT address_gedcomnr, address_id FROM humo_addresses
            WHERE address_tree_id='" . $this->tree_id . "' AND address_gedcomnr !='' ");
        $resi_id_array = array();
        while ($address_address_qryDb = $address_address_qry->fetch(PDO::FETCH_OBJ)) {
            if (in_array($address_address_qryDb->address_gedcomnr, $resi_array)) {
                $resi_id_array[] = $address_address_qryDb->address_id;
            }
        }

        // Find sources associated with address connections
        $address_connect2_qry = $this->dbh->query("SELECT connect_connect_id, connect_source_id
            FROM humo_connections WHERE connect_tree_id='" . $this->tree_id . "' AND connect_sub_kind = 'address_source'");
        while ($address_connect2_qry_qryDb = $address_connect2_qry->fetch(PDO::FETCH_OBJ)) {
            if (in_array($address_connect2_qry_qryDb->connect_connect_id, $resi_id_array)) {
                $source_array[] = $address_connect2_qry_qryDb->connect_source_id;
            }
        }

        // Find direct address sources
        $addressqry = $this->dbh->query("SELECT address_id, address_connect_sub_kind, address_connect_id
            FROM humo_addresses WHERE address_tree_id='" . $this->tree_id . "'");
        $source_address_array = array();
        while ($addressqryDb = $addressqry->fetch(PDO::FETCH_OBJ)) {
            if ($addressqryDb->address_connect_sub_kind == 'person' && in_array($addressqryDb->address_connect_id, $persids)) {
                $source_address_array[] = $addressqryDb->address_id;
            }
            if ($addressqryDb->address_connect_sub_kind == 'family' && in_array($addressqryDb->address_connect_id, $famsids)) {
                $source_address_array[] = $addressqryDb->address_id;
            }
        }

        $addresssourceqry = $this->dbh->query("SELECT connect_source_id, connect_connect_id
            FROM humo_connections WHERE connect_tree_id='" . $this->tree_id . "' AND connect_sub_kind LIKE 'address_%'");
        while ($addresssourceqryDb = $addresssourceqry->fetch(PDO::FETCH_OBJ)) {
            if (in_array($addresssourceqryDb->connect_connect_id, $source_address_array)) {
                $source_array[] = $addresssourceqryDb->connect_source_id;
            }
        }

        // Find sources referred to by events
        $eventqry = $this->dbh->query("SELECT event_id, event_connect_kind, event_connect_id FROM humo_events");
        $source_event_array = array();
        while ($eventqryDb = $eventqry->fetch(PDO::FETCH_OBJ)) {
            if (
                $eventqryDb->event_connect_kind == 'person'
                && $eventqryDb->event_connect_id != '' && in_array($eventqryDb->event_connect_id, $persids)
            ) {
                $source_event_array[] = $eventqryDb->event_id;
            }
            if (
                $eventqryDb->event_connect_kind == 'family'
                && $eventqryDb->event_connect_id != '' && in_array($eventqryDb->event_connect_id, $famsids)
            ) {
                $source_event_array[] = $eventqryDb->event_id;
            }
        }

        $eventsourceqry = $this->dbh->query("SELECT connect_source_id, connect_connect_id
            FROM humo_connections WHERE connect_tree_id='" . $this->tree_id . "' AND connect_sub_kind LIKE 'event_%'");
        while ($eventsourceqryDb = $eventsourceqry->fetch(PDO::FETCH_OBJ)) {
            if (in_array($eventsourceqryDb->connect_connect_id, $source_event_array)) {
                $source_array[] = $eventsourceqryDb->connect_source_id;
            }
        }

        // Eliminate duplicates
        if (isset($source_array)) {
            $source_array = array_unique($source_array);
        }

        return $source_array;
    }

    private function exportSourceRecords($fh, $export, $gedcom_texts, $source_array): void
    {
        $source_qry = $this->dbh->query("SELECT * FROM humo_sources WHERE source_tree_id='" . $this->tree_id . "'");
        while ($sourceDb = $source_qry->fetch(PDO::FETCH_OBJ)) {
            if ($export["part_tree"] == 'part' && !in_array($sourceDb->source_gedcomnr, $source_array)) {
                continue;
            }

            $this->buffer = '0 @' . $sourceDb->source_gedcomnr . "@ SOUR\r\n";

            if (isset($_POST['gedcom_status']) && $_POST['gedcom_status'] == 'yes') {
                echo $sourceDb->source_gedcomnr . ' ';
            }

            // *** Basic source data ***
            if ($sourceDb->source_title) {
                $this->writeLine(1, 'TITL', $sourceDb->source_title);
            }
            if ($sourceDb->source_abbr) {
                $this->writeLine(1, 'ABBR', $sourceDb->source_abbr);
            }

            // *** Source date and place - GEDCOM 7 uses DATA and EVEN tags ***
            $this->exportSourceDatePlace($sourceDb);

            // *** Additional source data ***
            if ($sourceDb->source_publ) {
                $this->writeLine(1, 'PUBL', $sourceDb->source_publ);
            }
            if ($sourceDb->source_refn) {
                $this->writeLine(1, 'REFN', $sourceDb->source_refn);
            }
            if ($sourceDb->source_auth) {
                $this->writeLine(1, 'AUTH', $sourceDb->source_auth);
            }
            if ($sourceDb->source_subj) {
                $this->writeLine(1, 'SUBJ', $sourceDb->source_subj);
            }
            if ($sourceDb->source_item) {
                $this->writeLine(1, 'ITEM', $sourceDb->source_item);
            }
            if ($sourceDb->source_kind) {
                $this->writeLine(1, 'KIND', $sourceDb->source_kind);
            }
            if ($sourceDb->source_text) {
                $this->writeNote(1, $sourceDb->source_text);
            }
            if (isset($sourceDb->source_status) && $sourceDb->source_status == 'restricted') {
                $this->writeLine(1, 'RESN', 'privacy');
            }

            if ($sourceDb->source_repo_gedcomnr) {
                $this->writeLine(1, 'REPO', '@' . $sourceDb->source_repo_gedcomnr . '@');
            }

            // *** Source pictures ***
            $source_pic_qry = $this->db_functions->get_events_connect('source', $sourceDb->source_gedcomnr, 'picture');
            foreach ($source_pic_qry as $source_picDb) {
                $this->writeLine(1, 'OBJE');
                $this->writeLine(2, 'FORM', 'jpg');
                $this->writeLine(2, 'FILE', $source_picDb->event_event);
                if ($source_picDb->event_date) {
                    $this->writeLine(2, 'DATE', $this->process_date($source_picDb->event_date));
                }

                if ($gedcom_texts == 'yes' && $source_picDb->event_text) {
                    $this->buffer .= '2 NOTE ' . $this->process_text(3, $source_picDb->event_text);
                }
            }

            // *** Datetime tracking ***
            $this->buffer .= $this->process_datetime('new', $sourceDb->source_new_datetime, $sourceDb->source_new_user_id);
            $this->buffer .= $this->process_datetime('changed', $sourceDb->source_changed_datetime, $sourceDb->source_changed_user_id);

            // *** Write source data ***
            $this->decode();
            fwrite($fh, $this->buffer);

            // *** Update progress ***
            $this->record_nr++;
            $this->perc = $this->update_bootstrap_bar();
        }
    }

    // TODO use general event date/place export processing functions
    private function exportSourceDatePlace($sourceDb): void
    {
        $buffer_temp = '';
        $tag_number = 1;
        if ($this->gedcom_version != '551') {
            $tag_number = 3;
        }

        if ($sourceDb->source_date) {
            $buffer_temp .= $tag_number . ' DATE ' . $this->process_date($sourceDb->source_date) . "\r\n";
        }
        if ($sourceDb->source_place) {
            $buffer_temp .= $tag_number . ' PLAC ' . $sourceDb->source_place . "\r\n";
        }

        // GEDCOM 7 uses extra tags 1 DATA and 2 EVEN
        if ($buffer_temp && $this->gedcom_version != '551') {
            $this->buffer .= '1 DATA' . "\r\n";
            $this->buffer .= '2 EVEN' . "\r\n";
        }
        $this->buffer .= $buffer_temp;
    }

    private function exportRepositories($fh): void
    {
        $repo_qry = $this->dbh->query("SELECT * FROM humo_repositories WHERE repo_tree_id='" . $this->tree_id . "' ORDER BY repo_name, repo_place");
        while ($repoDb = $repo_qry->fetch(PDO::FETCH_OBJ)) {
            $this->buffer = '0 @' . $repoDb->repo_gedcomnr . "@ REPO\r\n";

            if ($repoDb->repo_date) {
                $this->writeLine(1, 'DATE', $this->process_date($repoDb->repo_date));
            }
            if ($repoDb->repo_place) {
                $this->buffer .= $this->process_place($repoDb->repo_place, 1);
            }

            if ($repoDb->repo_name) {
                $this->writeLine(1, 'NAME', $repoDb->repo_name);
            }
            if ($repoDb->repo_text) {
                $this->writeNote(1, $repoDb->repo_text);
            }
            if ($repoDb->repo_address) {
                $this->buffer .= '1 ADDR ' . $this->process_text(2, $repoDb->repo_address);
            }

            if ($repoDb->repo_zip) {
                $this->writeLine(2, 'POST', $repoDb->repo_zip);
            }
            if ($repoDb->repo_phone) {
                $this->writeLine(1, 'PHON', $repoDb->repo_phone);
            }
            if ($repoDb->repo_mail) {
                $this->writeLine(1, 'EMAIL', $repoDb->repo_mail);
            }
            if ($repoDb->repo_url) {
                $this->writeLine(1, 'WWW', $repoDb->repo_url);
            }

            // *** Datetime tracking ***
            $this->buffer .= $this->process_datetime('new', $repoDb->repo_new_datetime, $repoDb->repo_new_user_id);
            $this->buffer .= $this->process_datetime('changed', $repoDb->repo_changed_datetime, $repoDb->repo_changed_user_id);

            // *** Write repository data ***
            $this->decode();
            fwrite($fh, $this->buffer);

            // *** Update progress ***
            $this->record_nr++;
            $this->perc = $this->update_bootstrap_bar();
        }
    }
}
