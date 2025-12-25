<?php

/**
 * GedcomExportFunctions - Base class for GEDCOM export utilities
 * 
 * Provides shared formatting and output functions used by all GEDCOM exporters
 * Dec. 2025 Extracted common functionality
 */

namespace Genealogy\Include;

use PDO;

abstract class GedcomExportFunctions
{
    protected $dbh;
    protected $db_functions;
    protected $tree_id = '';
    protected $gedcom_sources = '';
    protected $buffer = '';
    protected $gedcom_version = '551';
    protected $record_nr = 0;
    protected $step = 1;
    protected $devider = 50;
    protected $perc = 0;
    protected $noteids = array();

    /**
     * Set progress tracking parameters
     */
    public function setProgressTracking($record_nr, $step, $devider, $perc): void
    {
        $this->record_nr = $record_nr;
        $this->step = $step;
        $this->devider = $devider;
        $this->perc = $perc;
    }

    /**
     * Get current progress values
     */
    public function getProgressTracking(): array
    {
        return [
            'record_nr' => $this->record_nr,
            'step' => $this->step,
            'devider' => $this->devider,
            'perc' => $this->perc
        ];
    }

    /**
     * Helper to write a single GEDCOM line, skipping empty values
     */
    protected function writeLine(int $level, string $tag, ?string $value = null): void
    {
        $line = $level . ' ' . $tag;
        if ($value !== null && $value !== '') {
            $line .= ' ' . $value;
        }
        $this->buffer .= $line . "\r\n";
    }

    /**
     * Process date for GEDCOM version compatibility
     */
    protected function process_date($text): string
    {
        if ($this->gedcom_version == '551') {
            //
        } else {
            if ($text) {
                // *** Remove extra 0 for GEDCOM 7 export ***
                $text = str_replace('01 ', '1 ', $text);
                $text = str_replace('02 ', '2 ', $text);
                $text = str_replace('03 ', '3 ', $text);
                $text = str_replace('04 ', '4 ', $text);
                $text = str_replace('05 ', '5 ', $text);
                $text = str_replace('06 ', '6 ', $text);
                $text = str_replace('07 ', '7 ', $text);
                $text = str_replace('08 ', '8 ', $text);
                $text = str_replace('09 ', '9 ', $text);
            }
        }
        return $text;
    }

    /**
     * Process and format text for GEDCOM export with line wrapping
     */
    protected function process_text($level, $text, $extractnoteids = true): string
    {
        $text = str_replace("<br>", "", $text);
        $text = str_replace("\r", "", $text);

        // *** Export referenced texts ***
        if ($extractnoteids && substr($text, 0, 1) == '@') {
            $this->noteids[] = $text;
        }

        $regel = explode("\n", $text);
        // *** If text is too long split it, GEDCOM 5.5.1 specs: max. 255 characters including tag. ***
        $text = '';
        $text_processed = '';
        for ($j = 0; $j <= (count($regel) - 1); $j++) {
            $text = $regel[$j] . "\r\n";

            // *** CONC isn't allowed in GEDCOM 7.0 ***
            if ($this->gedcom_version == '551') {
                if (strlen($regel[$j]) > 150) {
                    $line_length = strlen($regel[$j]);
                    $words = explode(" ", $regel[$j]);
                    $new_line = '';
                    $new_line2 = '';
                    $characters = 0;
                    for ($x = 0; $x <= (count($words) - 1); $x++) {
                        if ($x > 0) {
                            $new_line .= ' ';
                            $new_line2 .= ' ';
                        }
                        $new_line .= $words[$x];
                        $new_line2 .= $words[$x];
                        $characters = (strlen($new_line2));
                        //if ($characters>145){
                        // *** Break line if there are >5 characters left AND there are >145 characters ***
                        if ($characters > 145 && $line_length - $characters > 5) {
                            $new_line .= "\r\n" . $level . " CONC";
                            $new_line2 = '';
                            $line_length = $line_length - $characters;
                        }
                    }
                    $text = $new_line . "\r\n";
                }
            }

            // *** First line is x NOTE, use CONT at other lines ***
            if ($j > 0) {
                if ($regel[$j] === '') {
                    $text = $level . ' CONT' . "\r\n";
                } else {
                    $text = $level . ' CONT ' . $regel[$j] . "\r\n";
                }
            }
            $text_processed .= $text;
        }
        return $text_processed;
    }

    /**
     * Write a NOTE field with proper text wrapping
     */
    protected function writeNote(int $level, string $text): void
    {
        if ($text && trim($text) !== '') {
            // Write NOTE tag with processed text inline (first line) and CONT for rest
            $this->buffer .= $level . ' NOTE ' . $this->process_text($level + 1, $text);
        }
    }

    /**
     * Process place information for GEDCOM output
     */
    protected function process_place($place, $number): string
    {
        // 2 PLAC Cleveland, Ohio, USA
        // 3 MAP
        // 4 LATI N41.500347
        // 4 LONG W81.66687
        $text = $number . ' PLAC ' . $place . "\r\n";
        if (isset($_POST['gedcom_geocode']) && $_POST['gedcom_geocode'] == 'yes') {
            $geo_location_sql = "SELECT * FROM humo_location WHERE location_lat IS NOT NULL AND location_location='" . addslashes($place) . "'";
            $geo_location_qry = $this->dbh->query($geo_location_sql);
            $geo_locationDb = $geo_location_qry->fetch(PDO::FETCH_OBJ);
            if ($geo_locationDb) {
                $text .= ($number + 1) . ' MAP' . "\r\n";

                $geocode = $geo_locationDb->location_lat;
                if (substr($geocode, 0, 1) == '-') {
                    $geocode = 'S' . substr($geocode, 1);
                } else {
                    $geocode = 'N' . $geocode;
                }
                $text .= ($number + 2) . ' LATI ' . $geocode . "\r\n";

                $geocode = $geo_locationDb->location_lng;
                if (substr($geocode, 0, 1) == '-') {
                    $geocode = 'W' . substr($geocode, 1);
                } else {
                    $geocode = 'E' . $geocode;
                }
                $text .= ($number + 2) . ' LONG ' . $geocode . "\r\n";
            }
        }
        return $text;
    }

    /**
     * Process datetime tracking information (new/changed timestamps)
     */
    protected function process_datetime($type, $datetime, $user_id): string
    {
        $buffer = '';
        if ($datetime && $datetime != '1970-01-01 00:00:01') {
            if ($type == 'new' && $this->gedcom_version == '551') {
                $buffer .= "1 _NEW\r\n";
            } elseif ($type == 'new') {
                $buffer .= "1 CREA\r\n";
            } else {
                $buffer .= "1 CHAN\r\n";
            }

            $export_date = strtoupper(date('d M Y', (strtotime($datetime))));
            $buffer .= "2 DATE " . $this->process_date($export_date) . "\r\n";

            $buffer .= "3 TIME " . date('H:i:s', (strtotime($datetime))) . "\r\n";

            if ($user_id) {
                $buffer .= "2 _USR " . $user_id . "\r\n";
            }
        }
        return $buffer;
    }

    /**
     * Handle character set conversion for ANSI export
     */
    protected function decode(): void
    {
        if (isset($_POST['gedcom_char_set']) && $_POST['gedcom_char_set'] == 'ANSI') {
            $this->buffer = iconv("UTF-8", "ISO-8859-15", $this->buffer);
        }
    }

    /**
     * Update progress bar display during export
     */
    protected function update_bootstrap_bar(): int
    {
        // Calculate the percentage
        if ($this->record_nr % $this->step == 0) {
            $perc = round(($this->record_nr / ($this->devider * $this->step)) * 100);
            if ($perc > 100) {
                $perc = 100;
            }
            // *** Bootstrap bar ***
?>
            <script>
                var bar = document.querySelector(".progress-bar");
                bar.style.width = <?= $perc; ?> + "%";
                bar.innerText = <?= $perc; ?> + "%";
            </script>

<?php

            // TODO These items don't work properly. Probably because of the for loops.
            // This is for the buffer achieve the minimum size in order to flush data
            //echo str_repeat(' ', 1024 * 64);
            //ob_flush();

            flush();
        }
        return $this->perc;
    }

    /**
     * Function to export all kind of sources including role, pages etc.
     * 
     * @param string $connect_kind Type of connection (person, family, address)
     * @param string $connect_sub_kind Sub-type of connection
     * @param string $connect_connect_id Connection ID
     * @param int $start_number Starting GEDCOM level number
     */
    public function sources_export($connect_kind, $connect_sub_kind, $connect_connect_id, $start_number): void
    {
        // *** Search for all connected sources ***
        $connect_qry = "SELECT * FROM humo_connections LEFT JOIN humo_sources ON source_gedcomnr=connect_source_id
            WHERE connect_tree_id='" . $this->tree_id . "' AND source_tree_id='" . $this->tree_id . "'
            AND connect_kind='" . $connect_kind . "'
            AND connect_sub_kind='" . $connect_sub_kind . "'
            AND connect_connect_id='" . $connect_connect_id . "'
            ORDER BY connect_order";
        $connect_sql = $this->dbh->query($connect_qry);
        while ($connectDb = $connect_sql->fetch(PDO::FETCH_OBJ)) {
            // *** Source contains title, can be connected to multiple items ***
            // 0 @S2@ SOUR
            // 1 ROLE ROL
            // 1 PAGE page
            $this->buffer .= $start_number . ' SOUR @' . $connectDb->connect_source_id . "@\r\n";
            if ($connectDb->connect_role) {
                $this->writeLine($start_number + 1, 'ROLE', $connectDb->connect_role);
            }
            if ($connectDb->connect_page) {
                $this->writeLine($start_number + 1, 'PAGE', $connectDb->connect_page);
            }
            if ($connectDb->connect_quality || $connectDb->connect_quality == '0') {
                $this->writeLine($start_number + 1, 'QUAY', (string)$connectDb->connect_quality);
            }

            // TODO Check GEDCOM 7.0 specs for proper way to store this information
            // *** Source citation (extra text by source) ***
            // 3 DATA
            // 4 DATE ......
            // 4 PLAC ....... (not in GEDOM specifications).
            // 4 TEXT text .....
            // 5 CONT ..........
            if ($connectDb->connect_text || $connectDb->connect_date || $connectDb->connect_place) {
                $this->buffer .= ($start_number + 1) . " DATA\r\n";

                if ($connectDb->connect_date) {
                    $this->writeLine($start_number + 2, 'DATE', $this->process_date($connectDb->connect_date));
                }

                if ($connectDb->connect_place) {
                    $this->writeLine($start_number + 2, 'PLAC', $connectDb->connect_place);
                }

                if ($connectDb->connect_text) {
                    $this->buffer .= ($start_number + 2) . ' TEXT ' . $this->process_text($start_number + 3, $connectDb->connect_text);
                }
            }
        }
    }

    /**
     * Export witness information for GEDCOM 5.5.1 and 7.0
     * 
     * @param string $event_connect_kind Connection type (e.g., 'MARR', 'baptism', 'MARR_REL')
     * @param string $event_connect_id Connection ID (GEDCOM number)
     * @param string $event_kind Event kind (e.g., 'witness')
     * @return string GEDCOM formatted witness data
     */
    public function export_witnesses($event_connect_kind, $event_connect_id, $event_kind): string
    {
        $witnesses = '';
        $witness_qry = $this->db_functions->get_events_connect($event_connect_kind, $event_connect_id, $event_kind);
        foreach ($witness_qry as $witnessDb) {
            if ($this->gedcom_version == '551') {
                // *** Baptise witness: 2 _WITN @I1@ or: 2 _WITN firstname lastname ***
                if ($witnessDb->event_connect_id2) {
                    $witnesses .= '2 WITN @' . $witnessDb->event_connect_id2 . "@\r\n";
                } else {
                    $witnesses .= '2 WITN ' . $witnessDb->event_event . "\r\n";
                }
            } else {
                // *** GEDCOM 7 ***
                // 1 BURI
                // 2 ASSO @I9@
                // 3 ROLE OTHER
                // 4 PHRASE funeral leader
                if ($witnessDb->event_connect_id2) {
                    // *** Connected person ***
                    $witnesses .= '2 ASSO @' . $witnessDb->event_connect_id2 . "@\r\n";
                } else {
                    // *** No person connected, text is used for name of person ***
                    // 2 ASSO @VOID@
                    // 3 PHRASE Mr Stockdale
                    // 3 ROLE OTHER
                    // 4 PHRASE Teacher -> event_event_extra?
                    $witnesses .= "2 ASSO @VOID@\r\n";
                    $witnesses .= '3 PHRASE ' . $witnessDb->event_event . "\r\n";
                }

                $witnesses .= '3 ROLE ' . $witnessDb->event_gedcom . "\r\n";

                // *** 4 PHRASE for role OTHER ***
                if ($witnessDb->event_gedcom == 'OTHER') {
                    $witnesses .= '4 PHRASE ' . $witnessDb->event_event_extra . "\r\n";
                }
            }
        }
        return $witnesses;
    }

    /**
     * Export address information with shared or non-shared address handling
     * 
     * @param string $connect_kind Connection type (person, family)
     * @param string $connect_sub_kind Sub-type of connection
     * @param string $connect_connect_id Connection ID
     */
    public function addresses_export($connect_kind, $connect_sub_kind, $connect_connect_id): void
    {
        // *** Addresses (shared addresses are no valid GEDCOM 5.5.1) ***
        // *** Living place ***
        // 1 RESI
        // 2 ADDR Ridderkerk
        // 1 RESI
        // 2 ADDR Slikkerveer

        $eventnr = 0;
        $connect_sql = $this->db_functions->get_connections_connect_id($connect_kind, $connect_sub_kind, $connect_connect_id);
        foreach ($connect_sql as $connectDb) {
            $addressDb = $this->db_functions->get_address($connectDb->connect_item_id);
            // *** Next items are only exported if Address is shared ***

            $export_addresses = false;
            if ($addressDb->address_shared == '1') $export_addresses = true;
            if (isset($_POST['gedcom_shared_addresses']) && $_POST['gedcom_shared_addresses'] == 'standard') {
                $export_addresses = false;
            }
            if ($export_addresses) {
                // *** Shared address ***
                // 1 RESI @R210@
                // 2 DATE 1 JAN 2021
                // 2 ROLE ROL
                $this->buffer .= '1 RESI @' . $connectDb->connect_item_id . "@\r\n";

                if ($connectDb->connect_date) {
                    $this->writeLine(2, 'DATE', $this->process_date($connectDb->connect_date));
                }
                if ($connectDb->connect_role) {
                    $this->writeLine(2, 'ROLE', $connectDb->connect_role);
                }

                // *** Extra text by address ***
                if ($connectDb->connect_text) {
                    // 2 DATA
                    // 3 TEXT text .....
                    // 4 CONT ..........
                    $this->buffer .= "2 DATA\r\n";
                    $this->buffer .= '3 TEXT ' . $this->process_text(4, $connectDb->connect_text);
                }

                // *** Source by address ***
                if ($this->gedcom_sources == 'yes') {
                    if ($connect_kind == 'person') {
                        $this->sources_export('person', 'pers_address_connect_source', $connectDb->connect_id, 2);
                    } else {
                        $this->sources_export('family', 'fam_address_connect_source', $connectDb->connect_id, 2);
                    }
                }
            } else {
                // *** Living place ***
                // 1 RESI
                // 2 ADDR Ridderkerk
                // 1 RESI
                // 2 ADDR Slikkerveer
                $this->buffer .= "1 RESI\r\n";

                // *** Export HuMo-genealogy address GEDCOM numbers ***
                $this->buffer .= '2 RIN ' . substr($connectDb->connect_item_id, 1) . "\r\n";

                $this->buffer .= '2 ADDR';
                if ($addressDb->address_address) {
                    $this->buffer .= ' ' . $addressDb->address_address;
                }
                $this->buffer .= "\r\n";
                if ($addressDb->address_place) {
                    $this->buffer .= '3 CITY ' . $addressDb->address_place . "\r\n";
                }
                if ($addressDb->address_zip) {
                    $this->buffer .= '3 POST ' . $addressDb->address_zip . "\r\n";
                }
                if ($addressDb->address_phone) {
                    $this->writeLine(2, 'PHON', $addressDb->address_phone);
                }
                if ($connectDb->connect_date) {
                    $this->writeLine(2, 'DATE', $this->process_date($connectDb->connect_date));
                }
                if ($addressDb->address_text) {
                    $this->buffer .= '2 NOTE ' . $this->process_text(3, $addressDb->address_text);
                }

                // *** Source by address ***
                if ($this->gedcom_sources == 'yes') {
                    if ($connect_kind == 'person') {
                        $this->sources_export('person', 'pers_address_connect_source', $connectDb->connect_id, 2);
                    } else {
                        $this->sources_export('family', 'fam_address_connect_source', $connectDb->connect_id, 2);
                    }

                    $this->sources_export('address', 'address_source', $addressDb->address_gedcomnr, 2);
                }
            }
        }
    }
}
