<?php

/**
 * GedcomExport class
 * 
 * Jun. 2025 Huub: rebuild to class GedcomExport.
 */

namespace Genealogy\Include;

use PDO;
use PDOException;

class GedcomExport extends GedcomExportFunctions
{
    public $db_functions, $humo_option;
    //private $tree_id;
    public $gedcom_sources = '';
    public $persids = array(), $famsids = array();

    public function __construct($dbh, $db_functions, $humo_option, $tree_id)
    {
        $this->dbh = $dbh;
        $this->db_functions = $db_functions;
        $this->humo_option = $humo_option;
        $this->tree_id = $tree_id;

        if (isset($_POST['gedcom_version'])) {
            $this->gedcom_version = $_POST['gedcom_version'];
        }

        if (isset($_POST['gedcom_sources'])) {
            $this->gedcom_sources = $_POST['gedcom_sources'];
        }
    }

    public function exportGedcom($export)
    {
        // Needed to process witnesses etc.
        if (isset($this->tree_id) && $this->tree_id) {
            $this->db_functions->set_tree_id($this->tree_id);
        }

        if (isset($this->tree_id) && isset($_POST['submit_button'])) {
            if ($export["part_tree"] == 'part' && isset($_POST['kind_tree']) && $_POST['kind_tree'] == "descendant") {
                // map descendants
                $desc_fams = 0;
                $desc_pers = $_POST['person'];
                $max_gens = $_POST['nr_generations'];

                $fam_search = $this->db_functions->get_person($desc_pers);
                $first_relation = $this->db_functions->get_first_relation($fam_search->pers_id);
                if (isset($first_relation->relation_gedcomnumber)) {
                    $desc_fams = $first_relation->relation_id;
                } elseif ($fam_search->parent_relation_gedcomnumber) {
                    $desc_fams = $fam_search->parent_relation_id;
                }

                $generation_number = 0;

                // *** Only use first marriage of selected person to avoid error. Other marriages will be processed in the function! ***
                $this->descendants($desc_fams, $desc_pers, $generation_number, $max_gens);
            }
            if ($export["part_tree"] == 'part' && isset($_POST['kind_tree']) && $_POST['kind_tree'] == "ancestor") {
                // map ancestors
                $anc_pers = $_POST['person'];
                $max_gens = $_POST['nr_generations'] + 2;
                $this->ancestors($anc_pers, $max_gens);
            }

            $gedcom_char_set = '';
            if (isset($_POST['gedcom_char_set'])) {
                $gedcom_char_set = $_POST['gedcom_char_set'];
            }

            $gedcom_texts = '';
            if (isset($_POST['gedcom_texts'])) {
                $gedcom_texts = $_POST['gedcom_texts'];
            }

            // PMB our minimal option
            //$gedcom_minimal = '';
            //if (isset($_POST['gedcom_minimal'])) {
            //    $gedcom_minimal = $_POST['gedcom_minimal'];
            //}

            $fh = fopen($export['path'] . $export['file_name'], 'w') or die("<b>ERROR: no permission to open a new file! Please check permissions of admin/gedcom_files folder!</b>");

            $this->buffer = '';

            // *** GEDCOM header ***
            $this->exportHeader($gedcom_char_set, $export);

            fwrite($fh, $this->buffer);
            //$this->buffer = str_replace("\n", "<br>", $this->buffer);
            //echo '<p>'.$this->buffer;

            // *** Count records in all tables for Bootstrap progress bar ***
            $total = $this->dbh->query("SELECT COUNT(*) FROM humo_persons WHERE pers_tree_id='" . $this->tree_id . "'");
            $total = $total->fetch();
            $nr_records = $total[0];

            $count_fam = $this->dbh->query("SELECT COUNT(fam_id) FROM humo_families WHERE fam_tree_id='" . $this->tree_id . "'");
            $count_famDb = $count_fam->fetch();
            $nr_records += $count_famDb[0];

            $total = $this->dbh->query("SELECT COUNT(*) FROM humo_sources WHERE source_tree_id='" . $this->tree_id . "'");
            $total = $total->fetch();
            $nr_records += $total[0];

            if ($gedcom_texts == 'yes') {
                $total = $this->dbh->query("SELECT COUNT(*) FROM humo_texts WHERE text_tree_id='" . $this->tree_id . "'");
                $total = $total->fetch();
                $nr_records += $total[0];
            }

            $total = $this->dbh->query("SELECT COUNT(*) FROM humo_addresses WHERE address_tree_id='" . $this->tree_id . "'");
            $total = $total->fetch();
            $nr_records += $total[0];

            $total = $this->dbh->query("SELECT COUNT(*) FROM humo_repositories WHERE repo_tree_id='" . $this->tree_id . "'");
            $total = $total->fetch();
            $nr_records += $total[0];

            // determines the steps in percentages.
            // regular: 2%
            $this->devider = 50;
            // 1% for larger files with over 200,000 lines
            if ($nr_records > 200000) {
                $this->devider = 100;
            }
            // 0.5% for very large files
            if ($nr_records > 1000000) {
                $this->devider = 200;
            }
            $this->step = round($nr_records / $this->devider);
            if ($this->step < 1) {
                $this->step = 1;
            }
            $this->perc = 0;
            $this->record_nr = 0;

            //echo $nr_records . '!' . $this->step . '!' . $this->devider . '!' . $this->perc;
            //$this->record_nr++;
            //$this->perc = $this->update_bootstrap_bar();

            /**
             * Export persons
             * 
             * Example:
             * 0 @I1181@ INDI
             * 1 RIN 1181
             * 1 REFN Eigencode
             * 1 NAME Voornaam/Achternaam/
             * 1 SEX M
             * 1 BIRT
             * 2 DATE 21 FEB 1960
             * 2 PLAC 1e woonplaats
             * 1 RESI
             * 2 ADDR 2e woonplaats
             * 1 RESI
             * 2 ADDR 3e woonplaats
             * 1 RESI
             * 2 ADDR 4e woonplaats
             * 1 OCCU 1e beroep
             * 1 OCCU 2e beroep
             * 1 EVEN
             * 2 TYPE living
             * 1 _COLOR 0
             * 1 NOTE @N51@
             * 1 FAMS @F10@
             * 1 FAMC @F8@
             * 1 _NEW
             * 2 TYPE 2
             * 2 DATE 8 JAN 2005
             * 3 TIME 20:31:24
             */
            $personExporter = new GedcomExportPersons($this->dbh, $this->db_functions, $this->tree_id, $this->gedcom_version, $this->gedcom_sources);
            $personExporter->setExportArrays($this->persids, $this->famsids, $this->noteids);
            $personExporter->setProgressTracking($this->record_nr, $this->step, $this->devider, $this->perc);
            $personExporter->exportPersons($fh, $export, $gedcom_texts);
            // Update progress tracking and noteids
            $progress = $personExporter->getProgressTracking();
            $this->record_nr = $progress['record_nr'];
            $this->step = $progress['step'];
            $this->devider = $progress['devider'];
            $this->perc = $progress['perc'];
            $this->noteids = $personExporter->getNoteids();

            /**
             * Export families
             * 
             * Example:
             * 0 @F1@ FAM
             * 1 HUSB @I2@
             * 1 WIFE @I3@
             * 1 MARL
             * 2 DATE 25 AUG 1683
             * 2 PLAC Arnhem
             * 1 MARR
             * 2 TYPE civil
             * 2 DATE 30 NOV 1683
             * 2 PLAC Arnhem
             * 2 NOTE @N311@
             * 1 CHIL @I4@
             * 1 CHIL @I5@
             * 1 CHIL @I6@
             */
            $familyExporter = new GedcomExportFamilies($this->dbh, $this->db_functions, $this->tree_id, $this->gedcom_version, $this->gedcom_sources);
            $familyExporter->setProgressTracking($this->record_nr, $this->step, $this->devider, $this->perc);
            $familyExporter->exportFamilies($fh, $export, $gedcom_texts);

            // Retrieve updated progress values
            $progress = $familyExporter->getProgressTracking();
            $this->record_nr = $progress['record_nr'];
            $this->perc = $progress['perc'];
            $this->noteids = $progress['noteids'];

            /**
             * Export sources and repositories
             * 
             * 0 @S1@ SOUR
             * 1 TITL Persoonskaarten
             * 1 DATE 24 JAN 2003
             * 1 PLAC Heerhugowaard
             * 1 REFN Pers-v
             * 1 PHOTO @#APLAATJES\AKTEMONS.GIF GIF@
             * 2 DSCR Afbeelding van Persoonskaarten
             * 1 PHOTO @#APLAATJES\HUUB&LIN.JPG JPG@
             * 2 DSCR Beschrijving
             * 1 NOTE Persoonskaarten (van overleden personen) besteld bij CBVG te Den Haag.
             */
            if ($_POST['export_type'] == 'normal') {
                $sourceExporter = new GedcomExportSources($this->dbh, $this->db_functions, $this->tree_id, $this->gedcom_version, $this->gedcom_sources);
                $sourceExporter->setProgressTracking($this->record_nr, $this->step, $this->devider, $this->perc);
                $sourceExporter->exportSources($fh, $export, $gedcom_texts, $this->persids, $this->famsids);

                // Retrieve updated progress values
                $progress = $sourceExporter->getProgressTracking();
                $this->record_nr = $progress['record_nr'];
                $this->perc = $progress['perc'];
            }

            // *** Addresses ***
            $export_addresses = true;
            if (isset($_POST['gedcom_shared_addresses']) && $_POST['gedcom_shared_addresses'] == 'standard') {
                $export_addresses = false;
            }
            if ($export_addresses) {
                $address_qry = $this->dbh->query("SELECT * FROM humo_addresses WHERE address_tree_id='" . $this->tree_id . "' AND address_shared='1'");
                while ($addressDb = $address_qry->fetch(PDO::FETCH_OBJ)) {
                    $this->buffer = '0 @' . $addressDb->address_gedcomnr . "@ RESI\r\n";

                    if ($addressDb->address_address) {
                        $this->writeLine(1, 'ADDR', $addressDb->address_address);
                    }
                    if ($addressDb->address_zip) {
                        $this->writeLine(1, 'ZIP', $addressDb->address_zip);
                    }
                    if ($addressDb->address_date) {
                        $this->writeLine(1, 'DATE', $this->process_date($addressDb->address_date));
                    }
                    if ($addressDb->address_place) {
                        $this->writeLine(1, 'PLAC', $addressDb->address_place);
                    }
                    if ($addressDb->address_phone) {
                        $this->writeLine(1, 'PHON', $addressDb->address_phone);
                    }
                    if ($this->gedcom_sources == 'yes') {
                        $this->sources_export('address', 'address_source', $addressDb->address_gedcomnr, 2);
                    }
                    if ($addressDb->address_text) {
                        $this->writeNote(1, $addressDb->address_text);
                    }

                    $this->decode();
                    fwrite($fh, $this->buffer);
                }
            }

            // *** Notes ***
            if ($gedcom_texts == 'yes') {
                $this->buffer = '';
                natsort($this->noteids);
                foreach ($this->noteids as $note_text) {
                    $stmt = $this->dbh->prepare("SELECT * FROM humo_texts WHERE text_tree_id=:text_tree_id AND text_gedcomnr=:text_gedcomnr");
                    $stmt->execute([
                        ':text_tree_id' => $this->tree_id,
                        ':text_gedcomnr' => substr($note_text, 1, -1)
                    ]);
                    while ($textDb = $stmt->fetch(PDO::FETCH_OBJ)) {
                        $this->buffer .= "0 " . $note_text . " NOTE\r\n";
                        $this->buffer .= '1 CONC ' . $this->process_text(1, $textDb->text_text);

                        $this->buffer .= $this->process_datetime('new', $textDb->text_new_datetime, $textDb->text_new_user_id);
                        $this->buffer .= $this->process_datetime('changed', $textDb->text_changed_datetime, $textDb->text_changed_user_id);
                    }
                }

                $this->decode();
                fwrite($fh, $this->buffer);

                $this->record_nr++;
                $this->perc = $this->update_bootstrap_bar();
            }

            // *** Bootstrap bar ***
?>
            <script>
                var bar = document.querySelector(".progress-bar");
                bar.style.width = 100 + "%";
                bar.innerText = 100 + "%";
            </script>

<?php
            fwrite($fh, '0 TRLR');
            fclose($fh);
        }
    }



    private function descendants($relation_id, $main_person, $generation_number, $max_generations): void
    {
        $family_nr = 1; //*** Process multiple families ***
        if ($max_generations < $generation_number) {
            return;
        }
        $generation_number++;
        // *** Count marriages of man ***
        // *** If needed show woman as main_person ***
        if ($relation_id == 0) {
            // single person
            $this->persids[] = $main_person;
            return;
        }

        // TODO only need partner numbers?
        $familyDb = $this->db_functions->get_family_with_id($relation_id);
        if (!isset($familyDb)) {
            echo __('No valid family number.');
        }

        $parent1 = '';
        $swap_parent1_parent2 = false;

        // *** Standard main_person is the man ***
        if ($familyDb->partner1_gedcomnumber) {
            $parent1 = $familyDb->partner1_gedcomnumber;
        }
        // *** If woman is selected, woman will be main_person ***
        if ($familyDb->partner2_gedcomnumber == $main_person) {
            $parent1 = $familyDb->partner2_gedcomnumber;
            $swap_parent1_parent2 = true;
        }

        // *** Check family with parent1: N.N. ***
        if ($parent1) {
            // *** Save man's families in array ***
            $personDb = $this->db_functions->get_person($parent1);
            $parent_relations = $this->db_functions->get_relations($personDb->pers_id);
        }

        // *** Loop multiple marriages of main_person ***
        foreach ($parent_relations as $parent_relation) {
            $familyDb = $this->db_functions->get_family_with_id($parent_relation->relation_id);

            /**
             * Parent1 (normally the father)
             */
            if ($familyDb->fam_kind != 'PRO-GEN') {
                //onecht kind, vrouw zonder man
                if ($family_nr == 1) {
                    // *** Show data of man ***

                    if ($swap_parent1_parent2 == true) {
                        // store I and Fs
                        $this->persids[] = $familyDb->partner2_gedcomnumber;
                        $relations = $this->db_functions->get_relations($personDb->pers_id);
                        foreach ($relations as $relation) {
                            $this->famsids[] = $relation->relation_gedcomnumber;
                        }
                    } else {
                        // store I and Fs
                        $this->persids[] = $familyDb->partner1_gedcomnumber;
                        $relations = $this->db_functions->get_relations($personDb->pers_id);
                        foreach ($relations as $relation) {
                            $this->famsids[] = $relation->relation_gedcomnumber;
                        }
                    }
                }
                $family_nr++;
            }

            /**
             * Parent2 (normally the mother)
             */
            if (isset($_POST['desc_spouses'])) {
                if ($swap_parent1_parent2 == true) {
                    $this->persids[] = $familyDb->partner1_gedcomnumber;
                    $desc_sp = $familyDb->partner1_gedcomnumber;
                } else {
                    $this->persids[] = $familyDb->partner2_gedcomnumber;
                    $desc_sp = $familyDb->partner2_gedcomnumber;
                }
            }
            if (isset($_POST['desc_sp_parents'])) {
                // if set, add parents of spouse
                $spqryDb = $this->db_functions->get_person($desc_sp);
                if (isset($spqryDb->parent_relation_gedcomnumber) && $spqryDb->parent_relation_gedcomnumber) {
                    $famqryDb = $this->db_functions->get_family($spqryDb->parent_relation_gedcomnumber);
                    if ($famqryDb->partner1_gedcomnumber) {
                        $this->persids[] = $famqryDb->partner1_gedcomnumber;
                    }
                    if ($famqryDb->partner2_gedcomnumber) {
                        $this->persids[] = $famqryDb->partner2_gedcomnumber;
                    }
                    $this->famsids[] = $spqryDb->parent_relation_gedcomnumber;
                }
            }

            /**
             * Children
             */
            $children = $this->db_functions->get_children($parent_relation->relation_id);
            if ($children) {
                foreach ($children as $child) {
                    $childFirstRelation = $this->db_functions->get_first_relation($child->person_id);
                    // *** Build descendant_report ***
                    if (isset($childFirstRelation->relation_id)) {
                        // *** 1st family of child ***
                        $this->descendants($childFirstRelation->relation_id, $child->person_gedcomnumber, $generation_number, $max_generations);  // recursive
                    } else {
                        // Child without own family
                        if ($max_generations >= $generation_number) {
                            //$childgn = $generation_number + 1;
                            $this->persids[] = $child->person_gedcomnumber;
                        }
                    }
                }
            }
        } // Show  multiple marriages
    }

    private function ancestors($person_id, $max_generations): void
    {
        $ancestor_array2[] = $person_id;
        $ancestor_number2[] = 1;
        $marriage_gedcomnumber2[] = 0;
        $generation = 1;
        $listed_array = array();

        // *** Loop for ancestor report ***
        while (isset($ancestor_array2[0])) {
            if ($max_generations <= $generation) {
                return;
            }

            unset($ancestor_array);
            $ancestor_array = $ancestor_array2;
            unset($ancestor_array2);

            unset($ancestor_number);
            $ancestor_number = $ancestor_number2;
            unset($ancestor_number2);

            unset($marriage_gedcomnumber);
            $marriage_gedcomnumber = $marriage_gedcomnumber2;
            unset($marriage_gedcomnumber2);

            // *** Loop per generation ***
            for ($i = 0; $i < count($ancestor_array); $i++) {
                //foreach ($ancestor_array as $i => $value){
                $listednr = '';
                foreach ($listed_array as $key => $value) {
                    if ($value == $ancestor_array[$i]) {
                        $listednr = $key;
                    }
                }
                if ($listednr == '') {
                    //if not listed yet, add person to array
                    $listed_array[$ancestor_number[$i]] = $ancestor_array[$i];
                }
                if ($ancestor_array[$i] != '0') {
                    $person_manDb = $this->db_functions->get_person($ancestor_array[$i]);
                    if (strtolower($person_manDb->pers_sexe) == 'm' && $ancestor_number[$i] > 1) {
                        $familyDb = $this->db_functions->get_family($marriage_gedcomnumber[$i]);
                        $person_womanDb = $this->db_functions->get_person($familyDb->fpartner2_gedcomnumber);
                    }
                    if ($listednr == '') {
                        //take I and F
                        if ($person_manDb->pers_gedcomnumber == $person_id) {
                            // for the base person we add spouse manually
                            $this->persids[] = $person_manDb->pers_gedcomnumber;

                            $relationsMan = $this->db_functions->get_relations($person_manDb->pers_id);
                            foreach ($relationsMan as $relationMan) {
                                $this->famsids[] = $relationMan->relation_gedcomnumber;

                                if (isset($_POST['ances_spouses'])) {
                                    // New nov. 2025: just for sure check man/ woman in all relations.
                                    if ($relationMan->partner_order == 1) {
                                        $partner_order = 2;
                                    } else {
                                        $partner_order = 1;
                                    }
                                    $sql = "SELECT * FROM humo_relations_persons
                                        WHERE relation_id = :relation_id AND relation_type='partner' AND partner_order = :partner_order";
                                    $stmt = $this->dbh->prepare($sql);
                                    $stmt->execute([
                                        ':relation_id' => $relationMan->relation_id,
                                        ':partner_order' => $partner_order
                                    ]);
                                    $relationsSpouse = $stmt->fetch(PDO::FETCH_OBJ);
                                    // we also include spouses of base person
                                    $this->persids[] = $relationsSpouse->person_gedcomnumber;
                                }
                            }
                        } else {
                            // any other person
                            $this->persids[] = $person_manDb->pers_gedcomnumber;
                        }

                        // if this is the last generation (max gen) we don't want the famc!
                        if ($person_manDb->parent_relation_gedcomnumber && $generation + 1 < $max_generations) {
                            $this->famsids[] = $person_manDb->parent_relation_gedcomnumber;
                            if (isset($_POST['ances_sibbl'])) {
                                // also get I numbers of sibblings
                                $sibbqryDb = $this->db_functions->get_family($person_manDb->parent_relation_gedcomnumber);
                                $children = $this->db_functions->get_children($sibbqryDb->pers_id);
                                foreach ($children as $child) {
                                    if ($child->person_gedcomnumber != $person_manDb->pers_gedcomnumber) {
                                        $this->persids[] = $child->person_gedcomnumber;
                                    }
                                }
                            }
                        }
                    } else {
                        // person was already listed
                        // do nothing
                    }

                    // == Check for parents
                    if ($person_manDb->parent_relation_gedcomnumber  && $listednr == '') {
                        $family_parentsDb = $this->db_functions->get_family($person_manDb->parent_relation_gedcomnumber);
                        if ($family_parentsDb->partner1_gedcomnumber) {
                            $ancestor_array2[] = $family_parentsDb->partner1_gedcomnumber;
                            $ancestor_number2[] = (2 * $ancestor_number[$i]);
                            $marriage_gedcomnumber2[] = $person_manDb->parent_relation_gedcomnumber;
                        }
                        if ($family_parentsDb->partner2_gedcomnumber) {
                            $ancestor_array2[] = $family_parentsDb->partner2_gedcomnumber;
                            $ancestor_number2[] = (2 * $ancestor_number[$i] + 1);
                            $marriage_gedcomnumber2[] = $person_manDb->parent_relation_gedcomnumber;
                        } else {
                            // *** N.N. name ***
                            $ancestor_array2[] = '0';
                            $ancestor_number2[] = (2 * $ancestor_number[$i] + 1);
                            $marriage_gedcomnumber2[] = $person_manDb->parent_relation_gedcomnumber;
                        }
                    }
                } else {
                    // *** Show N.N. person ***
                    $person_manDb = $this->db_functions->get_person($ancestor_array[$i]);
                    // take I (and F?)
                }
            }
            $generation++;
        }
    }

    /**
     * Export GEDCOM file header for both GEDCOM 5.5.1 and 7.0
     * 
     * @param string $gedcom_char_set Character set (UTF-8, ANSI, ASCII)
     * @param array $export Export configuration array
     * 
     * Example GEDCOM header:
     * 0 HEAD
     * 1 SOUR HuMo-genealogy
     * 2 VERS 12.0
     * 2 NAME HuMo-genealogy
     * 2 CORP HuMo-genealogy software
     * 3 ADDR https://humo-gen.com
     * 1 DATE 28 NOV 2025
     * 2 TIME 15:39:01
     * 1 SUBM @SUBM1@
     * 1 GEDC
     * 2 VERS 7.0
     */
    private function exportHeader($gedcom_char_set, $export): void
    {
        $this->writeLine(0, 'HEAD');
        $this->writeLine(1, 'SOUR', 'HuMo-genealogy');
        $this->writeLine(2, 'VERS', $this->humo_option["version"]);
        $this->writeLine(2, 'NAME', 'HuMo-genealogy');
        $this->writeLine(2, 'CORP', 'HuMo-genealogy software');
        $this->writeLine(3, 'ADDR', 'https://humo-gen.com');
        $this->writeLine(1, 'DATE', strtoupper(date('d M Y')));
        $this->writeLine(2, 'TIME', date('H:i:s'));
        $this->writeLine(1, 'SUBM', '@SUBM@');
        // 1 FILE isn't probably valid GEDCOM?
        //$this->writeLine(1, 'FILE', $export['file_name']);
        $this->writeLine(1, 'LANG', $this->humo_option["default_language"]);

        if ($this->gedcom_version == '551') {
            // *** GEDCOM 5.5.1 ***
            $this->writeLine(1, 'GEDC');
            $this->writeLine(2, 'VERS', '5.5.1');
            $this->writeLine(2, 'FORM', 'Lineage-Linked');

            // *** Character set ***
            if ($gedcom_char_set == 'UTF-8') {
                $this->writeLine(1, 'CHAR', 'UTF-8');
            } elseif ($gedcom_char_set == 'ANSI') {
                $this->writeLine(1, 'CHAR', 'ANSI');
            } else {
                $this->writeLine(1, 'CHAR', 'ASCII');
            }
        } else {
            // *** GEDCOM 7.0 ***
            $this->writeLine(1, 'GEDC');
            $this->writeLine(2, 'VERS', '7.0');
        }

        // *** Submitter information ***
        $this->writeLine(0, '@SUBM@ SUBM');
        if ($export['submit_name']) {
            $this->writeLine(1, 'NAME', $export['submit_name']);
        } else {
            $this->writeLine(1, 'NAME', 'Unknown');
        }

        if ($export['submit_address'] != '') {
            $this->writeLine(1, 'ADDR', $export['submit_address']);
            if ($export['submit_country'] != '') {
                $this->writeLine(2, 'CTRY', $export['submit_country']);
            }
        }

        if ($export['submit_mail'] != '') {
            $this->writeLine(1, 'EMAIL', $export['submit_mail']);
        }

        // *** Example of custom tags ***
        // 1 SCHMA
        // 2 TAG _SKYPEID http://xmlns.com/foaf/0.1/skypeID
        // 2 TAG _MEMBER http://xmlns.com/foaf/0.1/member
    }
}
