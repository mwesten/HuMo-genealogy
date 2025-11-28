<?php

/**
 * Original merge scripts made by Yossi. Rebuild to MVC by Huub.
 * Nov. 2025 Huub: several updates because of database normalisation and refactoring.
 */

namespace Genealogy\Admin\Models;

use Genealogy\Include\EventManager;
use PDO;

class TreeMergeModel extends AdminBaseModel
{
    private $leftPerson, $rightPerson, $relatives_merge;

    public function get_relatives_merge()
    {
        $this->relatives_merge = '';
        $relmerge = $this->dbh->query("SELECT * FROM humo_settings WHERE setting_variable = 'rel_merge_" . $this->tree_id . "'");
        if ($relmerge->rowCount() > 0) {
            $relmergeDb = $relmerge->fetch(PDO::FETCH_OBJ);
            $this->relatives_merge = $relmergeDb->setting_value;
        } else {
            // the rel_merge row doesn't exist yet - make it, with empty value
            $this->dbh->query("INSERT INTO humo_settings (setting_variable, setting_value) VALUES ('rel_merge_" . $this->tree_id . "', '')");
        }
        // TODO probably not needed if scripts are refactored.
        return $this->relatives_merge;
    }

    public function show_settings_page()
    {
        $showSettings = false;
        if (isset($_POST['settings']) || isset($_POST['reset'])) {
            $showSettings = true;
        }
        return $showSettings;
    }

    public function show_manual_page()
    {
        $showManual = false;
        if (isset($_POST['manual']) || isset($_POST["search1"]) || isset($_POST["search2"]) || isset($_POST["switch"])) {
            $showManual = true;
        }
        return $showManual;
    }

    public function update_settings()
    {
        if (isset($_POST['settings']) || isset($_POST['reset'])) {

            if (isset($_POST['merge_chars']) &&  is_numeric($_POST['merge_chars'])) {
                $this->db_functions->update_settings('merge_chars', $_POST['merge_chars']);
            }
            if (isset($_POST['merge_dates']) && ($_POST['merge_dates'] == 'YES' || $_POST['merge_dates'] == 'NO')) {
                $this->db_functions->update_settings('merge_dates', $_POST['merge_dates']);
            }
            if (isset($_POST['merge_lastname']) && ($_POST['merge_lastname'] == 'YES' || $_POST['merge_lastname'] == 'NO')) {
                $this->db_functions->update_settings('merge_lastname', $_POST['merge_lastname']);
            }
            if (isset($_POST['merge_firstname']) && ($_POST['merge_firstname'] == 'YES' || $_POST['merge_firstname'] == 'NO')) {
                $this->db_functions->update_settings('merge_firstname', $_POST['merge_firstname']);
            }
            if (isset($_POST['merge_parentsdate']) && ($_POST['merge_parentsdate'] == 'YES' || $_POST['merge_parentsdate'] == 'NO')) {
                $this->db_functions->update_settings('merge_parentsdate', $_POST['merge_parentsdate']);
            }

            if (isset($_POST['reset'])) {
                $this->db_functions->update_settings('merge_chars', '10');
                $this->db_functions->update_settings('merge_dates', 'YES');
                $this->db_functions->update_settings('merge_lastname', 'YES');
                $this->db_functions->update_settings('merge_firstname', 'YES');
                $this->db_functions->update_settings('merge_parentsdate', 'YES');
            }
        }
    }

    public function duplicateCompare()
    {
        $trees['left_person'] = '';
        $trees['right_person'] = '';
        $trees['no_more_duplicates'] = false;

        if (!isset($_POST['no_increase'])) {
            // no increase is used if "switch left and right" was chosen
            // present_compare is the pair that has to be shown next - saved to session
            $nr = ++$_SESSION['present_compare_' . $this->tree_id];
        } else {
            $nr = $_SESSION['present_compare_' . $this->tree_id];
        }
        if (isset($_POST['choice_nr'])) {
            // choice number is the number from the "skip to" pulldown - saved to a session
            $nr = $_POST['choice_nr'];
            $_SESSION['present_compare_' . $this->tree_id] = $_POST['choice_nr'];
        }

        // make sure the persons in the array are still there (in case in the mean time someone was merged)
        // after all, one person may be compared to more than one other person!
        while ($_SESSION['present_compare_' . $this->tree_id] < count($_SESSION['dupl_arr_' . $this->tree_id])) {
            $comp_set = explode(';', $_SESSION['dupl_arr_' . $this->tree_id][$nr]);
            $res = $this->db_functions->get_person_with_id($comp_set[0]);
            $res2 = $this->db_functions->get_person_with_id($comp_set[1]);
            if (!$res || !$res2) {
                // one or 2 persons are missing - continue with next pair
                $nr = ++$_SESSION['present_compare_' . $this->tree_id];
                continue; // look for next pair in array
            } else {
                $trees['left_person'] = $comp_set[0];
                $trees['right_person'] = $comp_set[1];
                if (isset($_POST['left']) && is_numeric($_POST['left'])) {
                    $trees['left_person'] = $_POST['left'];
                }
                if (isset($_POST['right']) && is_numeric($_POST['right'])) {
                    $trees['right_person'] = $_POST['right'];
                }
                break; // get out of the while loop. next loop will be called by skip or merge buttons
            }
        }

        if ($_SESSION['present_compare_' . $this->tree_id] >= count($_SESSION['dupl_arr_' . $this->tree_id])) {
            unset($_SESSION['present_compare_' . $this->tree_id]);
            $trees['left_person'] = '';
            $trees['right_person'] = '';
            $trees['no_more_duplicates'] = true;
        }
        return $trees;
    }

    public function relativesCompare()
    {
        $trees['left_person'] = '';
        $trees['right_person'] = '';
        $trees['show_merge_pair'] = false;
        $trees['no_more_duplicates'] = false;

        // this creates the pages that cycle through the surrounding relatives that have to be checked for merging
        // the "surrounding relatives" array is created in all merge modes (in the merge_them function) and saved to the database

        // if skip - delete pair from database string
        if (isset($_POST['skip_rel'])) {
            // remove first entry (that the admin decided not to merge) from string
            $relcomp = $this->dbh->query("SELECT * FROM humo_settings WHERE setting_variable = 'rel_merge_" . $this->tree_id . "'");
            $relcompDb = $relcomp->fetch(PDO::FETCH_OBJ);        // database row: I23@I300;I54@I304;I34@I430;
            $firstsemi = strpos($relcompDb->setting_value, ';') + 1;
            $string = substr($relcompDb->setting_value, $firstsemi);

            $this->db_functions->update_settings('rel_merge_' . $this->tree_id, $string);
            $trees['relatives_merge'] = $string;
        }

        // merge
        if (isset($_POST['rela'])) {
            // the merge button was used
            $trees['left_person'] = $_POST['left'];
            $trees['right_person'] = $_POST['right'];

            $this->merge_them($trees['left_person'], $trees['right_person'], "relatives");
        }

        $relcomp = $this->dbh->query("SELECT * FROM humo_settings WHERE setting_variable = 'rel_merge_" . $this->tree_id . "'");
        // TODO: change I numbers into ID's.
        $relcompDb = $relcomp->fetch(PDO::FETCH_OBJ);        // database row: I23@I300;I54@I304;I34@I430;
        if ($relcompDb->setting_value != '') {
            if (!isset($_POST['swap'])) {
                $allpairs = explode(';', $relcompDb->setting_value);  // $allpairs[0]:  I23@I300

                $pair = explode('@', $allpairs[0]); // $pair[0]:  I23;
                $lft = $pair[0];  // I23
                $rght = $pair[1]; // I300

                $leftDb = $this->db_functions->get_person($lft);
                $trees['left_person'] = $leftDb->pers_id;

                $rightDb = $this->db_functions->get_person($rght);
                $trees['right_person'] = $rightDb->pers_id;
            } else {
                // "switch left-right" button used"
                $trees['left_person'] = $_POST['left'];
                $trees['right_person'] = $_POST['right'];
            }
            $trees['show_merge_pair'] = true;
        } else {
            $trees['no_more_duplicates'] = true;
        }
        return $trees;
    }

    // Do merge and allow to continue with comparing duplicates
    // This is called up by the "Merge" button in manual and duplicate merge modes
    // TODO: better processing of $left and $right in this class.
    public function merge(): array
    {
        $results = [];
        if (isset($_POST['manu'])) {
            // Manual merge
            $left = $_POST['left'];
            $right = $_POST['right'];
            $results = $this->merge_them($left, $right, "man_dupl");
        } elseif (isset($_POST['dupl'])) {
            // Duplicate merge
            $nr = $_SESSION['present_compare_' . $this->tree_id];
            $comp_set = explode(';', $_SESSION['dupl_arr_' . $this->tree_id][$nr]);
            $left = $comp_set[0];
            $right = $comp_set[1];
            $results = $this->merge_them($left, $right, "man_dupl");
        }
        return $results;
    }

    // this is called when the "duplicate merge" button is used on the duplicate merge page
    // it creates the dupl_arr array with all duplicates found
    public function mergeDuplicate()
    {
        $humo_option = $this->humo_option;

        $count_duplicates = 0;

        $famname_search = '';
        if (isset($_POST['famname_search']) && $_POST['famname_search'] != "") {
            $famname_search = " AND pers_lastname = '" . $_POST['famname_search'] . "'";
        }
        $qry = "SELECT p.pers_id, p.pers_firstname, p.pers_lastname,
            b.event_date AS pers_birth_date,
            d.event_date AS pers_death_date
            FROM humo_persons p
            LEFT JOIN humo_events b ON b.person_id=p.pers_id AND b.event_kind='birth'
            LEFT JOIN humo_events d ON d.person_id=p.pers_id AND d.event_kind='death'
            WHERE p.pers_tree_id='" . $this->tree_id . "'" . $famname_search . " ORDER BY p.pers_id";
        $pers = $this->dbh->query($qry);
        unset($dupl_arr); // just to make sure...

        while ($persDb = $pers->fetch(PDO::FETCH_OBJ)) {
            // the exact phrasing of the query depends on the admin settings
            $qry2 = "SELECT p.pers_id, p.pers_firstname, p.pers_lastname,
            b.event_date AS pers_birth_date,
            d.event_date AS pers_death_date
            FROM humo_persons p
            LEFT JOIN humo_events b ON b.person_id=p.pers_id AND b.event_kind='birth'
            LEFT JOIN humo_events d ON d.person_id=p.pers_id AND d.event_kind='death'
            WHERE p.pers_tree_id='" . $this->tree_id . "' AND p.pers_id > " . $persDb->pers_id;

            if ($humo_option["merge_firstname"] == 'YES') {
                $qry2 .= " AND SUBSTR(p.pers_firstname,1," . $humo_option["merge_chars"] . ") = SUBSTR('" . $persDb->pers_firstname . "',1," . $humo_option["merge_chars"] . ")";
            } else {
                $qry2 .= " AND p.pers_firstname != '' AND SUBSTR(p.pers_firstname,1," . $humo_option["merge_chars"] . ") = SUBSTR('" . $persDb->pers_firstname . "',1," . $humo_option["merge_chars"] . ")";
            }
            if ($humo_option["merge_lastname"] == 'YES') {
                $qry2 .= " AND p.pers_lastname ='" . $persDb->pers_lastname . "' ";
            } else {
                $qry2 .= " AND p.pers_lastname != '' AND p.pers_lastname ='" . $persDb->pers_lastname . "' ";
            }
            if ($humo_option["merge_dates"] == "YES") {
                $qry2 .= " AND (b.event_date ='" . $persDb->pers_birth_date . "' OR b.event_date ='' OR '" . $persDb->pers_birth_date . "'='') ";
                $qry2 .= " AND (d.event_date ='" . $persDb->pers_death_date . "' OR d.event_date ='' OR '" . $persDb->pers_death_date . "'='') ";
            } else {
                $qry2 .= " AND (( b.event_date != '' AND b.event_date ='" . $persDb->pers_birth_date . "' AND !(d.event_date != '" . $persDb->pers_death_date . "'))
            OR
            (  d.event_date != '' AND d.event_date ='" . $persDb->pers_death_date . "' AND !(b.event_date != '" . $persDb->pers_birth_date . "')) )";
            }

            $pers2 = $this->dbh->query($qry2);
            if ($pers2) {
                while ($pers2Db = $pers2->fetch(PDO::FETCH_OBJ)) {
                    $dupl_arr[] = $persDb->pers_id . ';' . $pers2Db->pers_id;
                }
            }
        }
        if (isset($dupl_arr)) {
            $_SESSION['dupl_arr_' . $this->tree_id] = $dupl_arr;
            $_SESSION['present_compare_' . $this->tree_id] = -1;
            $count_duplicates = count($dupl_arr);
        }
        return $count_duplicates;
    }

    public function mergeAutomatically()
    {
        $humo_option = $this->humo_option;

        $trees['merges'] = 0;
        $qry = "SELECT p.pers_id, p.pers_lastname, p.pers_firstname,
            b.event_date AS pers_birth_date,
            d.event_date AS pers_death_date,
            rp.relation_id AS parent_relation_id
            FROM humo_persons p
            LEFT JOIN humo_events b ON b.event_tree_id='" . $this->tree_id . "' 
            AND b.person_id=p.pers_id AND b.event_kind='birth'
            LEFT JOIN humo_events d ON d.event_tree_id='" . $this->tree_id . "' 
            AND d.person_id=p.pers_id AND d.event_kind='death'
            LEFT JOIN humo_relations_persons rp ON rp.tree_id='" . $this->tree_id . "' 
            AND rp.person_id = p.pers_id AND rp.relation_type = 'child'
            WHERE p.pers_tree_id='" . $this->tree_id . "'
            AND p.pers_lastname !=''
            AND p.pers_firstname !=''
            AND (b.event_date !='' OR d.event_date !='')
            AND rp.relation_gedcomnumber !='' 
            ORDER BY p.pers_id";

        $pers = $this->dbh->query($qry);
        while ($persDb = $pers->fetch(PDO::FETCH_OBJ)) {
            $qry2 = "SELECT p.pers_id, p.pers_lastname, p.pers_firstname, 
                    b.event_date AS pers_birth_date,
                    d.event_date AS pers_death_date,
                    rp.relation_id AS parent_relation_id
                FROM humo_persons p
                LEFT JOIN humo_events b ON b.person_id=p.pers_id AND b.event_kind='birth'
                LEFT JOIN humo_events d ON d.person_id=p.pers_id AND d.event_kind='death'
                LEFT JOIN humo_relations_persons rp ON rp.person_id = p.pers_id AND rp.relation_type = 'child'
                WHERE p.pers_tree_id='" . $this->tree_id . "'
                    AND p.pers_id > " . $persDb->pers_id . "
                    AND (p.pers_lastname !='' AND p.pers_lastname = '" . $persDb->pers_lastname . "')
                    AND (p.pers_firstname !='' AND p.pers_firstname = '" . $persDb->pers_firstname . "')
                    AND ((b.event_date !='' AND b.event_date ='" . $persDb->pers_birth_date . "')
                    OR (d.event_date !='' AND d.event_date ='" . $persDb->pers_death_date . "'))
                    AND rp.relation_gedcomnumber !='' 
                ORDER BY p.pers_id";
            $pers2 = $this->dbh->query($qry2);
            if ($pers2) {
                while ($pers2Db = $pers2->fetch(PDO::FETCH_OBJ)) {
                    // get the two families
                    $qry = "SELECT
                            p1.person_id   AS partner1_id,
                            p1.person_gedcomnumber AS partner1_gedcomnumber,
                            p2.person_id   AS partner2_id,
                            p2.person_gedcomnumber AS partner2_gedcomnumber,
                            e.event_date   AS fam_marr_date
                        FROM humo_families f
                        LEFT JOIN humo_events e
                            ON e.relation_id = f.fam_id
                            AND e.event_kind = 'marriage'
                        LEFT JOIN humo_relations_persons p1
                            ON p1.relation_id = f.fam_id
                            AND p1.relation_type = 'partner'
                            AND p1.partner_order = 1
                        LEFT JOIN humo_relations_persons p2
                            ON p2.relation_id = f.fam_id
                            AND p2.relation_type = 'partner'
                            AND p2.partner_order = 2
                        WHERE f.fam_id = :fam_id";
                    $stmt = $this->dbh->prepare($qry);

                    // Person
                    $stmt->execute([
                        ':fam_id' => $persDb->parent_relation_id
                    ]);
                    $fam1Db = $stmt->fetch(PDO::FETCH_OBJ);

                    // Person 2
                    $stmt->execute([
                        ':fam_id' => $pers2Db->parent_relation_id
                    ]);
                    $fam2Db = $stmt->fetch(PDO::FETCH_OBJ);

                    //if ($fam1->rowCount() > 0 && $fam2->rowCount() > 0) {
                    if ($fam1Db && $fam2Db) {
                        $go = 1;
                        if ($humo_option["merge_parentsdate"] == 'YES') {
                            // we want to check for wedding date of parents
                            if ($fam1Db->fam_marr_date != '' && $fam1Db->fam_marr_date == $fam2Db->fam_marr_date) {
                                $go = 1;
                            } else {
                                $go = 0;  // no wedding date or no match --> no merge!
                            }
                        }

                        if ($go) {
                            // no use doing all this if the marriage date doesn't match
                            $qry = "SELECT pers_lastname, pers_firstname FROM humo_persons WHERE pers_id='" . $fam1Db->partner1_id . "'";
                            $fath1 = $this->dbh->query($qry);
                            $fath1Db = $fath1->fetch(PDO::FETCH_OBJ);

                            $qry = "SELECT pers_lastname, pers_firstname FROM humo_persons WHERE pers_id='" . $fam1Db->partner2_id . "'";
                            $moth1 = $this->dbh->query($qry);
                            $moth1Db = $moth1->fetch(PDO::FETCH_OBJ);

                            $qry = "SELECT pers_lastname, pers_firstname FROM humo_persons WHERE pers_id='" . $fam2Db->partner1_id . "'";
                            $fath2 = $this->dbh->query($qry);
                            $fath2Db = $fath2->fetch(PDO::FETCH_OBJ);

                            $qry = "SELECT pers_lastname, pers_firstname FROM humo_persons WHERE pers_id='" . $fam2Db->partner2_id . "'";
                            $moth2 = $this->dbh->query($qry);
                            $moth2Db = $moth2->fetch(PDO::FETCH_OBJ);

                            if (($fath1->rowCount() > 0 && $moth1->rowCount() > 0
                                    && $fath2->rowCount() > 0 and $moth2->rowCount() > 0)
                                && ($fath1Db->pers_lastname != '' && $fath1Db->pers_lastname == $fath2Db->pers_lastname
                                    && $moth1Db->pers_lastname != '' && $moth1Db->pers_lastname == $moth2Db->pers_lastname
                                    && $fath1Db->pers_firstname != '' && $fath1Db->pers_firstname == $fath2Db->pers_firstname
                                    && $moth1Db->pers_firstname != '' && $moth1Db->pers_firstname == $moth2Db->pers_firstname)
                            ) {
                                $this->merge_them($persDb->pers_id, $pers2Db->pers_id, 'automatic');
                                $trees['mergedlist'][] = $persDb->pers_id;
                                $trees['merges']++;
                            }
                        }
                    }
                }
            }
        }
        return $trees;
    }

    private function merge_them($left, $right, $mode)
    {
        // merge algorithm - merge right into left
        // 1. if right has a relation with different wife - this Fxx is added to left's relations (in humo_person)
        //    and in humo_family the Ixx of right is replaced with the Ixx of left
        //    Right's Ixx is deleted
        // 2. if right has relations with identical wife - children are added to left's Fxx (in humo_family)
        //    and with each child the famc is changed to left's fams
        //    Right's Fxx is deleted
        //    Right's Ixx is deleted
        // 3. In either case whether right has family or not, if right has famc then in
        //    humo_family in right's parents Fxx, the child's Ixx is changed from right's to left's

        //$this->dbh->beginTransaction();
        //try {

        //$this->validateInput($left, $right);

        [$this->leftPerson, $this->rightPerson] = $this->loadPersons($left, $right);
        [$leftRelations, $rightRelations] = $this->loadRelations();

        // TODO rebuild loops (function allready prepared, see below)
        /*
        $sameSpousePairs = $this->findSameSpouseFamilies($leftRelations, $rightRelations);
        if (!empty($sameSpousePairs)) {
            $this->movePartnerRelationsToLeft();
            $this->mergeChildrenForSameSpouses($sameSpousePairs);
            $this->transferFamilyFields($sameSpousePairs);
            $this->transferFamilySources($sameSpousePairs);
            $this->deleteDuplicateFamilies($sameSpousePairs);
            $this->queuePotentialChildDuplicates($sameSpousePairs);
        } else {
            $this->moveUniquePartnerRelationsToLeft();
            $this->queuePotentialSpouseDuplicates($leftRelations, $rightRelations);
        }
        */

        if (count($rightRelations) > 0) {
            $spouse1 = '';
            $same_spouse = false; // will be made true if identical spouses found in next "if"

            if (count($leftRelations) > 0) {
                $leftRelationDb = [];  // Initialize as arrays
                $rightRelationDb = [];
                $sp1 = [];

                // Start searching for spouses with same ged nr (were merged earlier) of both persons
                foreach ($leftRelations as $leftRelation) {
                    $leftFamily = $this->db_functions->get_family_with_id($leftRelation->relation_id);
                    if ($leftRelation->partner_order == 1) {
                        $leftSpouseId = $leftFamily->partner2_id;
                        $spouse1 = $leftFamily->partner2_id;
                    } else {
                        $leftSpouseId = $leftFamily->partner1_id;
                        $spouse1 = $leftFamily->partner1_id;
                    }

                    foreach ($rightRelations as $rightRelation) {
                        $rightFamily = $this->db_functions->get_family_with_id($rightRelation->relation_id);
                        if ($rightRelation->partner_order == 1) {
                            $rightSpouseId = $rightFamily->partner2_id;
                        } else {
                            $rightSpouseId = $rightFamily->partner1_id;
                        }

                        if ($leftSpouseId == $rightSpouseId) {
                            // Found identical spouse, these relations have to be merged
                            $same_spouse = true;
                            // Array of identical spouses (there may be more than one if they were merged earlier!)
                            $leftRelationDb[] = $leftFamily;
                            $rightRelationDb[] = $rightFamily;
                            $sp1[] = $spouse1;
                        }
                    }
                }

                if ($same_spouse == true) {
                    $this->movePartnerRelationsToLeft();
                    $this->mergeChildrenForSameSpouses($leftRelationDb, $rightRelationDb);
                    $this->transferFamilyFields($leftRelationDb, $rightRelationDb);
                    $this->transferFamilySources($leftRelationDb, $rightRelationDb);
                    $this->deleteDuplicateFamilies($leftRelationDb, $rightRelationDb);

                    // *** Queue SPOUSE duplicates (different spouses with similar names) ***
                    $this->queuePotentialSpouseDuplicates1($leftRelations, $rightRelations, $leftRelationDb);
                }
            }
            $this->queuePotentialSpouseDuplicates($leftRelations, $rightRelations, $same_spouse);
        }

        $this->reassignParentLinks();
        $this->mergeVitalEvents($mode);
        $this->mergeOtherEventsAddressesSources($mode);
        $this->cleanupRightPersonReferences();
        $this->updateRelativesQueueSetting();

        return $this->buildResult($mode);

        //} catch (\Throwable $e) {
        //    $this->dbh->rollBack();
        //    throw $e;
        //}
    }

    /**
     * Find families where left and right persons share the same spouse.
     * Returns an array of pairs: [ ['leftFam' => object, 'rightFam' => object, 'spouse_id' => int], ... ]
     */
    /*
    private function findSameSpouseFamilies(array $leftRelations, array $rightRelations): array
    {
        $pairs = [];
        if (empty($leftRelations) || empty($rightRelations)) {
            return $pairs;
        }

        // Build map spouse_id => left family
        $leftBySpouse = [];
        foreach ($leftRelations as $lr) {
            $lf = $this->db_functions->get_family_with_id($lr->relation_id);
            if (!$lf) {
                continue;
            }
            // Determine spouse_id relative to left person partner_order
            $spouseId = ($lr->partner_order == 1) ? ($lf->partner2_id ?? null) : ($lf->partner1_id ?? null);
            if ($spouseId) {
                // A person may have multiple families with the same spouse (rare). Keep all.
                $leftBySpouse[$spouseId][] = $lf;
            }
        }

        foreach ($rightRelations as $rr) {
            $rf = $this->db_functions->get_family_with_id($rr->relation_id);
            if (!$rf) {
                continue;
            }
            $rightSpouseId = ($rr->partner_order == 1) ? ($rf->partner2_id ?? null) : ($rf->partner1_id ?? null);
            if (!$rightSpouseId) {
                continue;
            }

            if (!empty($leftBySpouse[$rightSpouseId])) {
                // For each left family that has the same spouse, create a pair
                foreach ($leftBySpouse[$rightSpouseId] as $lf) {
                    // Skip when both families are the same row
                    if (isset($lf->fam_id, $rf->fam_id) && $lf->fam_id === $rf->fam_id) {
                        continue;
                    }
                    $pairs[] = [
                        'leftFam' => $lf,
                        'rightFam' => $rf,
                        'spouse_id' => $rightSpouseId,
                    ];
                }
            }
        }

        return $pairs;
    }
    */

    // Left has one or more fams with same wife (spouse was already merged)
    private function movePartnerRelationsToLeft()
    {
        // *** Move all relations of right person to the left person ***
        $this->dbh->query(
            "UPDATE humo_relations_persons SET
                person_id = '" . $this->leftPerson->pers_id . "',
                person_gedcomnumber = '" . $this->leftPerson->pers_gedcomnumber . "'
                WHERE person_id = '" . $this->rightPerson->pers_id . "' AND relation_type='partner'"
        );
    }

    // If right has children - add them to the left F
    private function mergeChildrenForSameSpouses($leftRelationDb, $rightRelationDb)
    {
        // *** Check children ***
        for ($i = 0; $i < count($leftRelationDb); $i++) {
            // with all identical spouses
            $children1 = $this->db_functions->get_children($leftRelationDb[$i]->fam_id);
            $children2 = $this->db_functions->get_children($rightRelationDb[$i]->fam_id);
            $children1_ids = [];
            $children2_ids = [];
            if ($children1) {
                foreach ($children1 as $child) {
                    if (isset($child->person_id)) {
                        $children1_ids[] = $child->person_id;
                    }
                }
            }
            if ($children2) {
                foreach ($children2 as $child) {
                    if (isset($child->person_id)) {
                        $children2_ids[] = $child->person_id;
                    }
                }
            }
            // Now you can compare the arrays, using for example:
            $common_children = array_intersect($children1_ids, $children2_ids);
            $unique_to_children1 = array_diff($children1_ids, $children2_ids);
            $unique_to_children2 = array_diff($children2_ids, $children1_ids);

            // *** Remove common children from right family ***
            foreach ($common_children as $child_id) {
                $this->dbh->query(
                    "DELETE FROM humo_relations_persons 
                        WHERE tree_id = '" . $this->tree_id . "' 
                        AND relation_id = '" . $rightRelationDb[$i]->fam_id . "' 
                        AND person_id = '" . $child_id . "'
                        AND relation_type = 'child'"
                );
            }

            // *** Add unique children from right family to left family ***
            foreach ($unique_to_children2 as $child_id) {
                $this->dbh->query(
                    "UPDATE humo_relations_persons 
                        SET relation_id = '" . $leftRelationDb[$i]->fam_id . "',
                        relation_gedcomnumber = '" . $leftRelationDb[$i]->fam_gedcomnumber . "'
                        WHERE tree_id = '" . $this->tree_id . "' 
                        AND relation_id = '" . $rightRelationDb[$i]->fam_id . "' 
                        AND person_id = '" . $child_id . "'
                        AND relation_type = 'child'"
                );

                // *** Also check for possible duplicate children (comparing names), add them to merge array ***
                $child2Db = $this->db_functions->get_person_with_id($child_id);
                foreach ($unique_to_children1 as $child_id1) {
                    //compare names of children to name of newly added child
                    $child1Db = $this->db_functions->get_person_with_id($child_id1);
                    if (
                        isset($child1Db->pers_lastname) && isset($child2Db->pers_lastname)
                        && $child1Db->pers_lastname == $child2Db->pers_lastname
                        && substr($child1Db->pers_firstname, 0, $this->humo_option["merge_chars"]) === substr($child2Db->pers_firstname, 0, $this->humo_option["merge_chars"])
                    ) {
                        $string1 = $child1Db->pers_gedcomnumber . '@' . $child2Db->pers_gedcomnumber . ';';
                        $string2 = $child2Db->pers_gedcomnumber . '@' . $child1Db->pers_gedcomnumber . ';';
                        // Make sure this pair doesn't exist already in the string
                        if (strstr($this->relatives_merge, $string1) === false && strstr($this->relatives_merge, $string2) === false) {
                            $this->relatives_merge .= $string1;
                            $this->db_functions->update_settings('rel_merge_' . $this->tree_id, $this->relatives_merge);
                        }
                    }
                }
            }
        }
    }

    private function transferFamilyFields($leftRelationDb, $rightRelationDb)
    {
        // Move all unique partner relations from right person to left person (excluding the duplicate relations we already merged)
        $duplicate_relation_ids = array_map(function ($rel) {
            return $rel->fam_id;
        }, $rightRelationDb);

        $this->dbh->query(
            "UPDATE humo_relations_persons SET
            person_id = '" . $this->leftPerson->pers_id . "',
            person_gedcomnumber = '" . $this->leftPerson->pers_gedcomnumber . "'
            WHERE person_id = '" . $this->rightPerson->pers_id . "' 
            AND relation_type = 'partner'
            AND relation_id NOT IN (" . implode(',', $duplicate_relation_ids) . ")"
        );


        // Remove the duplicate partner relations from right person's spouse as well
        for ($i = 0; $i < count($leftRelationDb); $i++) {
            if (isset($sp1[$i])) {
                $this->dbh->query(
                    "DELETE FROM humo_relations_persons 
                    WHERE relation_id = '" . $rightRelationDb[$i]->fam_id . "' 
                    AND person_id = '" . $sp1[$i] . "'
                    AND relation_type = 'partner'"
                );
            }
        }

        // before we delete the F's of duplicate wifes from the database, we first check if they have items
        // that are not known in the "receiving" F's. If so, we copy it to the corresponding left families
        // to make one Db query only, we first put the necessary fields and values in an array
        for ($i = 0; $i < count($leftRelationDb); $i++) {
            if ($leftRelationDb[$i]->fam_kind == '' and $rightRelationDb[$i]->fam_kind != '') {
                $fam_items[$i]["fam_kind"] = $rightRelationDb[$i]->fam_kind;
            }
            if ($leftRelationDb[$i]->fam_relation_date == '' && $rightRelationDb[$i]->fam_relation_date != '') {
                $fam_items[$i]["fam_relation_date"] = $rightRelationDb[$i]->fam_relation_date;
            }
            if ($leftRelationDb[$i]->fam_relation_place == '' && $rightRelationDb[$i]->fam_relation_place != '') {
                $fam_items[$i]["fam_relation_place"] = $rightRelationDb[$i]->fam_relation_place;
            }
            if ($leftRelationDb[$i]->fam_relation_text == '' && $rightRelationDb[$i]->fam_relation_text != '') {
                $fam_items[$i]["fam_relation_text"] = $rightRelationDb[$i]->fam_relation_text;
            }
            //if($leftRelationDb[$i]->fam_relation_source=='' AND $rightRelationDb[$i]->fam_relation_source!='') {
            //  $fam_items[$i]["fam_relation_source"] = $rightRelationDb[$i]->fam_relation_source;
            //}
            if ($leftRelationDb[$i]->fam_relation_end_date == '' && $rightRelationDb[$i]->fam_relation_end_date != '') {
                $fam_items[$i]["fam_relation_end_date"] = $rightRelationDb[$i]->fam_relation_end_date;
            }
            if ($leftRelationDb[$i]->fam_marr_notice_date == '' && $rightRelationDb[$i]->fam_marr_notice_date != '') {
                $fam_items[$i]["fam_marr_notice_date"] = $rightRelationDb[$i]->fam_marr_notice_date;
            }
            if ($leftRelationDb[$i]->fam_marr_notice_place == '' && $rightRelationDb[$i]->fam_marr_notice_place != '') {
                $fam_items[$i]["fam_marr_notice_place"] = $rightRelationDb[$i]->fam_marr_notice_place;
            }
            if ($leftRelationDb[$i]->fam_marr_notice_text == '' && $rightRelationDb[$i]->fam_marr_notice_text != '') {
                $fam_items[$i]["fam_marr_notice_text"] = $rightRelationDb[$i]->fam_marr_notice_text;
            }
            //if($leftRelationDb[$i]->fam_marr_notice_source=='' AND $rightRelationDb[$i]->fam_marr_notice_source!='') {
            //  $fam_items[$i]["fam_marr_notice_source"] = $rightRelationDb[$i]->fam_marr_notice_source;
            //}
            if ($leftRelationDb[$i]->fam_marr_date == '' && $rightRelationDb[$i]->fam_marr_date != '') {
                $fam_items[$i]["fam_marr_date"] = $rightRelationDb[$i]->fam_marr_date;
            }
            if ($leftRelationDb[$i]->fam_marr_place == '' && $rightRelationDb[$i]->fam_marr_place != '') {
                $fam_items[$i]["fam_marr_place"] = $rightRelationDb[$i]->fam_marr_place;
            }
            if ($leftRelationDb[$i]->fam_marr_text == '' && $rightRelationDb[$i]->fam_marr_text != '') {
                $fam_items[$i]["fam_marr_text"] = $rightRelationDb[$i]->fam_marr_text;
            }
            //if($leftRelationDb[$i]->fam_marr_source=='' AND $rightRelationDb[$i]->fam_marr_source!='') {
            //  $fam_items[$i]["fam_marr_source"] = $rightRelationDb[$i]->fam_marr_source;
            //}
            if ($leftRelationDb[$i]->fam_marr_authority == '' && $rightRelationDb[$i]->fam_marr_authority != '') {
                $fam_items[$i]["fam_marr_authority"] = $rightRelationDb[$i]->fam_marr_authority;
            }
            if ($leftRelationDb[$i]->fam_marr_church_notice_date == '' && $rightRelationDb[$i]->fam_marr_church_notice_date != '') {
                $fam_items[$i]["fam_marr_church_notice_date"] = $rightRelationDb[$i]->fam_marr_church_notice_date;
            }
            if ($leftRelationDb[$i]->fam_marr_church_notice_place == '' && $rightRelationDb[$i]->fam_marr_church_notice_place != '') {
                $fam_items[$i]["fam_marr_church_notice_place"] = $rightRelationDb[$i]->fam_marr_church_notice_place;
            }
            if ($leftRelationDb[$i]->fam_marr_church_notice_text == '' && $rightRelationDb[$i]->fam_marr_church_notice_text != '') {
                $fam_items[$i]["fam_marr_church_notice_text"] = $rightRelationDb[$i]->fam_marr_church_notice_text;
            }
            //if($leftRelationDb[$i]->fam_marr_church_notice_source=='' AND $rightRelationDb[$i]->fam_marr_church_notice_source!='') {
            //  $fam_items[$i]["fam_marr_church_notice_source"] = $rightRelationDb[$i]->fam_marr_church_notice_source;
            //}
            if ($leftRelationDb[$i]->fam_marr_church_date == '' && $rightRelationDb[$i]->fam_marr_church_date != '') {
                $fam_items[$i]["fam_marr_church_date"] = $rightRelationDb[$i]->fam_marr_church_date;
            }
            if ($leftRelationDb[$i]->fam_marr_church_place == '' && $rightRelationDb[$i]->fam_marr_church_place != '') {
                $fam_items[$i]["fam_marr_church_place"] = $rightRelationDb[$i]->fam_marr_church_place;
            }
            if ($leftRelationDb[$i]->fam_marr_church_text == '' && $rightRelationDb[$i]->fam_marr_church_text != '') {
                $fam_items[$i]["fam_marr_church_text"] = $rightRelationDb[$i]->fam_marr_church_text;
            }
            //if($leftRelationDb[$i]->fam_marr_church_source=='' AND $rightRelationDb[$i]->fam_marr_church_source!='') {
            //  $fam_items[$i]["fam_marr_church_source"] = $rightRelationDb[$i]->fam_marr_church_source;
            //}
            if ($leftRelationDb[$i]->fam_religion == '' && $rightRelationDb[$i]->fam_religion != '') {
                $fam_items[$i]["fam_religion"] = $rightRelationDb[$i]->fam_religion;
            }
            if ($leftRelationDb[$i]->fam_div_date == '' && $rightRelationDb[$i]->fam_div_date != '') {
                $fam_items[$i]["fam_div_date"] = $rightRelationDb[$i]->fam_div_date;
            }
            if ($leftRelationDb[$i]->fam_div_place == '' && $rightRelationDb[$i]->fam_div_place != '') {
                $fam_items[$i]["fam_div_place"] = $rightRelationDb[$i]->fam_div_place;
            }
            if ($leftRelationDb[$i]->fam_div_text == '' && $rightRelationDb[$i]->fam_div_text != '') {
                $fam_items[$i]["fam_div_text"] = $rightRelationDb[$i]->fam_div_text;
            }
            //if($leftRelationDb[$i]->fam_div_source=='' AND $rightRelationDb[$i]->fam_div_source!='') {
            //  $fam_items[$i]["fam_div_source"] = $rightRelationDb[$i]->fam_div_source;
            //}
            if ($leftRelationDb[$i]->fam_div_authority == '' && $rightRelationDb[$i]->fam_div_authority != '') {
                $fam_items[$i]["fam_div_authority"] = $rightRelationDb[$i]->fam_div_authority;
            }
            if ($leftRelationDb[$i]->fam_text == '' && $rightRelationDb[$i]->fam_text != '') {
                $fam_items[$i]["fam_text"] = $rightRelationDb[$i]->fam_text;
            }
            //if($leftRelationDb[$i]->fam_text_source=='' AND $rightRelationDb[$i]->fam_text_source!='') {
            //  $fam_items[$i]["fam_text_source"] = $rightRelationDb[$i]->fam_text_source;
            //}
        }
        for ($i = 0; $i < count($leftRelationDb); $i++) {
            if (isset($fam_items[$i])) {
                $item_string = '';
                foreach ($fam_items[$i] as $key => $value) {
                    $item_string .= $key . "='" . $value . "',";
                }
                $item_string = substr($item_string, 0, -1); // take off last comma

                $qry = "UPDATE humo_families SET " . $item_string . " WHERE fam_tree_id='" . $this->tree_id . "' AND fam_gedcomnumber ='" . $leftRelationDb[$i]->fam_gedcomnumber . "'";
                $this->dbh->query($qry);
            }
        }
    }

    // *** Process sources like: fam_relation_source, fam_marr_notice_source, fam_marr_source, fam_marr_church_notice_source, fam_marr_church_source, fam_text_source ***
    // *** Nov. 2025 rebuild function ***
    private function transferFamilySources($leftRelationDb, $rightRelationDb)
    {
        /*
        // - new piece for fam sources that were removed in the code above)
        for ($i = 0; $i < count($leftRelationDb); $i++) {
            $qry = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_relation_source'";
            $sourDb = $this->dbh->query($qry);
            if ($sourDb->rowCount() == 0) {
                // no fam sources of the sub kind for this fam
                $qry2 = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_relation_source'";
                $sourDb2 = $this->dbh->query($qry2);
                if ($sourDb2->rowCount() > 0) {
                    // second fam has source of this sub kind - transfer these sources to left fam
                    $qry3 = "UPDATE humo_connections SET connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' WHERE connect_tree_id ='" . $this->tree_id . "'  AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_relation_source'";
                    $this->dbh->query($qry3);
                }
            }

            $qry = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_notice_source'";
            $sourDb = $this->dbh->query($qry);
            if ($sourDb->rowCount() == 0) {
                // no fam sources of the sub kind for this fam
                $qry2 = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_notice_source'";
                $sourDb2 = $this->dbh->query($qry2);
                if ($sourDb2->rowCount() > 0) {
                    // second fam has source of this sub kind - transfer these sources to left fam
                    $qry3 = "UPDATE humo_connections SET connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' WHERE connect_tree_id ='" . $this->tree_id . "'  AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_notice_source'";
                    $this->dbh->query($qry3);
                }
            }

            $qry = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_source'";
            $sourDb = $this->dbh->query($qry);
            if ($sourDb->rowCount() == 0) {
                // no fam sources of the sub kind for this fam
                $qry2 = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_source'";
                $sourDb2 = $this->dbh->query($qry2);
                if ($sourDb2->rowCount() > 0) {
                    // second fam has source of this sub kind - transfer these sources to left fam
                    $qry3 = "UPDATE humo_connections SET connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' WHERE connect_tree_id ='" . $this->tree_id . "'  AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_source'";
                    $this->dbh->query($qry3);
                }
            }

            $qry = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_church_notice_source'";
            $sourDb = $this->dbh->query($qry);
            if ($sourDb->rowCount() == 0) {
                // no fam sources of the sub kind for this fam
                $qry2 = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_church_notice_source'";
                $sourDb2 = $this->dbh->query($qry2);
                if ($sourDb2->rowCount() > 0) {
                    // second fam has source of this sub kind - transfer these sources to left fam
                    $qry3 = "UPDATE humo_connections SET connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' WHERE connect_tree_id ='" . $this->tree_id . "'  AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_church_notice_source'";
                    $this->dbh->query($qry3);
                }
            }

            $qry = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_church_source'";
            $sourDb = $this->dbh->query($qry);
            if ($sourDb->rowCount() == 0) {
                // no fam sources of the sub kind for this fam
                $qry2 = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_church_source'";
                $sourDb2 = $this->dbh->query($qry2);
                if ($sourDb2->rowCount() > 0) {
                    // second fam has source of this sub kind - transfer these sources to left fam
                    $qry3 = "UPDATE humo_connections SET connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' WHERE connect_tree_id ='" . $this->tree_id . "'  AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_marr_church_source'";
                    $this->dbh->query($qry3);
                }
            }

            $qry = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_text_source'";
            $sourDb = $this->dbh->query($qry);
            if ($sourDb->rowCount() == 0) {
                // no fam sources of the sub kind for this fam
                $qry2 = "SELECT * FROM humo_connections WHERE connect_tree_id ='" . $this->tree_id . "' AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_text_source'";
                $sourDb2 = $this->dbh->query($qry2);
                if ($sourDb2->rowCount() > 0) {
                    // second fam has source of this sub kind - transfer these sources to left fam
                    $qry3 = "UPDATE humo_connections SET connect_connect_id = '" . $leftRelationDb[$i]->fam_gedcomnumber . "' WHERE connect_tree_id ='" . $this->tree_id . "'  AND connect_connect_id = '" . $rightRelationDb[$i]->fam_gedcomnumber . "' AND connect_kind = 'family' AND connect_sub_kind = 'fam_text_source'";
                    $this->dbh->query($qry3);
                }
            }
        }
        */


        if (empty($leftRelationDb)) {
            return;
        }

        // Build arrays of GEDCOM numbers for batch query
        $rightGedNums = array_map(fn($f) => $f->fam_gedcomnumber, $rightRelationDb);
        $leftGedNums = array_map(fn($f) => $f->fam_gedcomnumber, $leftRelationDb);

        // Get ALL sources for both left and right families in one query
        $placeholders = implode(',', array_fill(0, count($rightGedNums) + count($leftGedNums), '?'));
        $stmt = $this->dbh->prepare(
            "SELECT connect_id, connect_connect_id, connect_sub_kind, connect_source_id
            FROM humo_connections 
            WHERE connect_tree_id = ? 
            AND connect_kind = 'family'
            AND connect_sub_kind LIKE '%_source'
            AND connect_connect_id IN ($placeholders)"
        );

        $allGedNums = array_merge($leftGedNums, $rightGedNums);
        $stmt->execute(array_merge([$this->tree_id], $allGedNums));

        // Organize sources by family GEDCOM number and sub_kind
        $sourcesByFamily = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['connect_connect_id'] . '|' . $row['connect_sub_kind'];
            $sourcesByFamily[$key][] = $row;
        }

        // Process each pair of duplicate families
        for ($i = 0; $i < count($leftRelationDb); $i++) {
            $leftGed = $leftRelationDb[$i]->fam_gedcomnumber;
            $rightGed = $rightRelationDb[$i]->fam_gedcomnumber;

            // Find all source sub_kinds that exist for the right family
            $rightSourceTypes = [];
            foreach ($sourcesByFamily as $key => $sources) {
                if (strpos($key, $rightGed . '|') === 0) {
                    $subKind = explode('|', $key)[1];
                    $rightSourceTypes[$subKind] = $sources;
                }
            }

            // Transfer each source type from right to left if left doesn't have it
            foreach ($rightSourceTypes as $subKind => $rightSources) {
                $leftKey = $leftGed . '|' . $subKind;

                // Check if left already has this source type
                if (!isset($sourcesByFamily[$leftKey])) {
                    // Left doesn't have it - transfer all sources of this type from right to left
                    $sourceIds = array_map(fn($s) => $s['connect_id'], $rightSources);

                    if (!empty($sourceIds)) {
                        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
                        $updateStmt = $this->dbh->prepare(
                            "UPDATE humo_connections SET connect_connect_id = ? WHERE connect_id IN ($placeholders)"
                        );
                        $updateStmt->execute(array_merge([$leftGed], $sourceIds));
                    }
                }
            }
        }
    }

    private function deleteDuplicateFamilies($leftRelationDb, $rightRelationDb)
    {
        // delete F's that belonged to identical right spouse(s)
        for ($i = 0; $i < count($leftRelationDb); $i++) {
            $qry = "DELETE FROM humo_events
                WHERE event_tree_id='" . $this->tree_id . "'
                AND (event_connect_kind='family' OR event_kind='ASSO')
                AND event_connect_id ='" . $rightRelationDb[$i]->fam_gedcomnumber . "'";
            $this->dbh->query($qry);

            // for each of the identical spouses
            $qry = "DELETE FROM humo_families
                WHERE fam_tree_id='" . $this->tree_id . "' 
                AND fam_gedcomnumber ='" . $rightRelationDb[$i]->fam_gedcomnumber . "'";
            $this->dbh->query($qry);

            // Substract 1 family from the number of families counter in the family tree.
            $sql = "UPDATE humo_trees SET tree_families=tree_families-1 WHERE tree_id='" . $this->tree_id . "'";
            $this->dbh->query($sql);

            // CLEANUP: also delete this F from other tables where it may appear
            $qry = "DELETE FROM humo_addresses
                WHERE address_tree_id='" . $this->tree_id . "' 
                AND address_connect_sub_kind='family'
                AND address_connect_id ='" . $rightRelationDb[$i]->fam_gedcomnumber . "'";
            $this->dbh->query($qry);

            $qry = "DELETE FROM humo_connections
                WHERE connect_tree_id='" . $this->tree_id . "'
                AND connect_connect_id ='" . $rightRelationDb[$i]->fam_gedcomnumber . "'";
            $this->dbh->query($qry);

            // Nov. 2025: Delete family relations for this family
            $qry = "DELETE FROM humo_relations_persons 
                WHERE relation_id = '" . $rightRelationDb[$i]->fam_id . "'";
            $this->dbh->query($qry);
        }
    }

    private function queuePotentialSpouseDuplicates1($leftRelations, $rightRelations, $leftRelationDb)
    {
        // Right had more than the identical spouse(s). maybe they need merging.
        if (count($rightRelations) > count($leftRelationDb)) {
            foreach ($leftRelations as $leftRel) {
                $fam1Db = $this->db_functions->get_family_partners($leftRel->relation_id);
                $sp_ged = $fam1Db->partner2_id;
                if ($this->leftPerson->pers_sexe == "F") {
                    $sp_ged = $fam1Db->partner1_id;
                }

                $qry = "SELECT * FROM humo_persons WHERE pers_id ='" . $sp_ged . "'";
                $spo1 = $this->dbh->query($qry);
                $spo1Db = $spo1->fetch(PDO::FETCH_OBJ);
                if ($spo1->rowCount() > 0) {
                    foreach ($rightRelations as $rightRel) {
                        $fam2Db = $this->db_functions->get_family_partners($rightRel->relation_id);
                        $sp_ged = $fam2Db->partner2_id;
                        if ($this->leftPerson->pers_sexe == "F") {
                            $sp_ged = $fam2Db->partner1_id;
                        }

                        $qry = "SELECT * FROM humo_persons WHERE pers_id ='" . $sp_ged . "'";
                        $spo2 = $this->dbh->query($qry);
                        $spo2Db = $spo2->fetch(PDO::FETCH_OBJ);
                        if ($spo2->rowCount() > 0 && ($spo1Db->pers_lastname == $spo2Db->pers_lastname
                            && substr($spo1Db->pers_firstname, 0, $this->humo_option["merge_chars"]) === substr($spo2Db->pers_firstname, 0, $this->humo_option["merge_chars"]))) {
                            $string1 = $spo1Db->pers_gedcomnumber . '@' . $spo2Db->pers_gedcomnumber . ';';
                            $string2 = $spo2Db->pers_gedcomnumber . '@' . $spo1Db->pers_gedcomnumber . ';';
                            // make sure this pair doesn't appear already in the string
                            if (strstr($this->relatives_merge, $string1) === false && strstr($this->relatives_merge, $string2) === false) {
                                $this->relatives_merge .= $string1;
                            }
                            $this->db_functions->update_settings('rel_merge_' . $this->tree_id, $this->relatives_merge);
                        }
                    }
                }
            }
        }
    }

    private function queuePotentialSpouseDuplicates($leftRelations, $rightRelations, $same_spouse)
    {
        if (!$leftRelations || $same_spouse == false) {
            // left has no fams or fams with different spouses than right -> add fams to left
            // Add right's relations to left's relations
            $this->dbh->query(
                "UPDATE humo_relations_persons SET
                person_id = '" . $this->leftPerson->pers_id . "',
                person_gedcomnumber = '" . $this->leftPerson->pers_gedcomnumber . "'
                WHERE person_id = '" . $this->rightPerson->pers_id . "' AND relation_type = 'partner'"
            );

            // check for spouses to be added to relative merge string:
            //if ($leftRelation && $same_spouse == false) {
            if ($leftRelations && $same_spouse == false) {
                foreach ($leftRelations as $leftRel) {
                    $fam1Db = $this->db_functions->get_family_partners($leftRel->relation_id);
                    $sp_ged = $fam1Db->partner1_id;
                    // TODO use partner_order?
                    if ($this->leftPerson->pers_sexe == "F") {
                        $sp_ged = $fam1Db->partner2_id;
                    }

                    $qry = "SELECT * FROM humo_persons WHERE pers_id ='" . $sp_ged . "'";
                    $spo1 = $this->dbh->query($qry);
                    $spo1Db = $spo1->fetch(PDO::FETCH_OBJ);
                    if ($spo1->rowCount() > 0) {
                        //for ($f = 0; $f < count($rightfam); $f++) {
                        foreach ($rightRelations as $rightRel) {
                            $fam2Db = $this->db_functions->get_family_partners($rightRel->relation_id);
                            $sp_ged = $fam2Db->partner1_id;
                            if ($this->leftPerson->pers_sexe == "F") {
                                $sp_ged = $fam2Db->partner2_id;
                            }

                            $qry = "SELECT * FROM humo_persons WHERE pers_id ='" . $sp_ged . "'";
                            $spo2 = $this->dbh->query($qry);
                            $spo2Db = $spo2->fetch(PDO::FETCH_OBJ);
                            if ($spo2->rowCount() > 0 && ($spo1Db->pers_lastname == $spo2Db->pers_lastname && substr($spo1Db->pers_firstname, 0, $this->humo_option["merge_chars"]) === substr($spo2Db->pers_firstname, 0, $this->humo_option["merge_chars"]))) {
                                // Check GEDCOM numbers, otherwise left and right could be the same person
                                if ($spo1Db->pers_gedcomnumber != $spo2Db->pers_gedcomnumber) {
                                    $string1 = $spo1Db->pers_gedcomnumber . '@' . $spo2Db->pers_gedcomnumber . ';';
                                    $string2 = $spo2Db->pers_gedcomnumber . '@' . $spo1Db->pers_gedcomnumber . ';';
                                    // make sure this pair doesn't already exist in the string
                                    if (strstr($this->relatives_merge, $string1) === false && strstr($this->relatives_merge, $string2) === false) {
                                        $this->relatives_merge .= $string1;
                                    }
                                    $this->db_functions->update_settings('rel_merge_' . $this->tree_id, $this->relatives_merge);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function reassignParentLinks()
    {
        if ($this->rightPerson->parent_relation_id) {
            // if the two merged persons had a different parent set (e.i. parents aren't merged yet)
            // then change right's children id's to left's id's
            // (because right I will be deleted and as long as the double parents aren't merged we don't want errors
            // when accessing the children!

            $parqry = "SELECT * FROM humo_families WHERE fam_id ='" . $this->rightPerson->parent_relation_id . "'";
            $parfam = $this->dbh->query($parqry);
            $parfamDb = $parfam->fetch(PDO::FETCH_OBJ);

            $children_of_parents = null;
            if (isset($parfamDb->fam_id)) {
                $children_of_parents = $this->db_functions->get_children($parfamDb->fam_id);
            }

            if (!$this->leftPerson->parent_relation_id || $this->leftPerson->parent_relation_id != $this->rightPerson->parent_relation_id) {
                // left has no parents or a different parent set (at least one parent not merged yet)
                // --> change right I for left I in right's parents' F
                if (isset($children_of_parents) && is_array($children_of_parents)) {
                    foreach ($children_of_parents as $child) {
                        if (isset($child->person_id) && $child->person_id == $this->rightPerson->pers_id) {
                            // Update the child to point to the left person instead of the right
                            $this->dbh->query(
                                "UPDATE humo_relations_persons 
                                    SET person_id = '" . $this->leftPerson->pers_id . "',
                                    person_gedcomnumber = '" . $this->leftPerson->pers_gedcomnumber . "'
                                    WHERE tree_id = '" . $this->tree_id . "' 
                                    AND relation_id = '" . $parfamDb->fam_id . "' 
                                    AND person_id = '" . $this->rightPerson->pers_id . "' 
                                    AND relation_type = 'child'"
                            );
                        }
                    }
                }

                // check if to add to relatives merge string
                if ($this->leftPerson->parent_relation_id && $this->leftPerson->parent_relation_id != $this->rightPerson->parent_relation_id) {
                    // there is a double set of parents - these have to be merged by the user! Save in variables
                    $par1Db = $this->db_functions->get_family_partners($this->leftPerson->parent_relation_id);
                    $par2Db = $this->db_functions->get_family_partners($this->rightPerson->parent_relation_id);

                    // add the parents to string of surrounding relatives to be merged
                    // to help later with exploding, sets are separated by ";" and left and right are separated by "@"
                    if (
                        isset($par1Db->partner1_gedcomnumber) && $par1Db->partner1_gedcomnumber != '0'
                        && isset($par2Db->partner1_gedcomnumber) && $par2Db->partner1_gedcomnumber != '0' && $par1Db->partner1_gedcomnumber != $par2Db->partner1_gedcomnumber
                    ) {
                        // make sure none of the two fathers is N.N. and that this father is not merged already!
                        $string1 = $par1Db->partner1_gedcomnumber . '@' . $par2Db->partner1_gedcomnumber . ";";
                        $string2 = $par2Db->partner1_gedcomnumber . '@' . $par1Db->partner1_gedcomnumber . ";";
                        // make sure this pair doesn't appear already in the string
                        if (strstr($this->relatives_merge, $string1) === false && strstr($this->relatives_merge, $string2) === false) {
                            $this->relatives_merge .= $string1;
                        }
                    } elseif ((!isset($par1Db->partner1_gedcomnumber) || $par1Db->partner1_gedcomnumber == '0') && isset($par2Db->partner1_gedcomnumber) && $par2Db->partner1_gedcomnumber != '0') {
                        // left father is N.N. so move right father to left F
                        $this->dbh->query("UPDATE humo_families SET partner1_gedcomnumber = '" . $par2Db->partner1_gedcomnumber . "'
                            WHERE fam_id ='" . $this->leftPerson->parent_relation_id . "'");
                    }

                    if (
                        isset($par1Db->partner2_gedcomnumber) && $par1Db->partner2_gedcomnumber != '0'
                        && isset($par2Db->partner2_gedcomnumber) && $par2Db->partner2_gedcomnumber != '0' && $par1Db->partner2_gedcomnumber != $par2Db->partner2_gedcomnumber
                    ) {
                        // make sure none of the two mothers is N.N. and that this mother is not merged already!
                        $string1 = $par1Db->partner2_gedcomnumber . '@' . $par2Db->partner2_gedcomnumber . ";";
                        $string2 = $par2Db->partner2_gedcomnumber . '@' . $par1Db->partner2_gedcomnumber . ";";
                        if (strstr($this->relatives_merge, $string1) === false && strstr($this->relatives_merge, $string2) === false) {
                            // make sure this pair doesn't appear already in the string
                            $this->relatives_merge .= $string1;
                        }
                    } elseif ((!isset($par1Db->partner2_gedcomnumber) || $par1Db->partner2_gedcomnumber == '0') && isset($par2Db->partner2_gedcomnumber) && $par2Db->partner2_gedcomnumber != '0') {
                        // left mother is N.N. so move right mother to left F
                        $this->dbh->query(
                            "UPDATE humo_relations_persons SET
                                person_id = '" . $par2Db->partner2_id . "',
                                person_gedcomnumber = '" . $par2Db->partner2_gedcomnumber . "'
                                WHERE relation_id = '" . $this->leftPerson->parent_relation_id . "'
                                AND relation_type = 'partner'
                                AND partner_order = 2"
                        );
                    }

                    $this->db_functions->update_settings('rel_merge_' . $this->tree_id, $this->relatives_merge);
                }
                if (!$this->leftPerson->parent_relation_id) {
                    // assign right's parents relation to left.
                    $this->dbh->query(
                        "UPDATE humo_relations_persons SET
                            person_id = '" . $this->leftPerson->pers_id . "',
                            person_gedcomnumber = '" . $this->leftPerson->pers_gedcomnumber . "'
                         WHERE relation_id = '" . $parfamDb->fam_id . "'
                           AND person_id = '" . $this->rightPerson->pers_id . "'
                           AND relation_type = 'child'"
                    );
                }
            } elseif ($this->leftPerson->parent_relation_id && $this->leftPerson->parent_relation_id == $this->rightPerson->parent_relation_id) {
                // same parent set (double children in one family) just remove right's I from F
                // we can use right's F since this is also left's F....
                $this->dbh->query(
                    "DELETE FROM humo_relations_persons 
                        WHERE tree_id = '" . $this->tree_id . "' 
                        AND relation_id = '" . $parfamDb->fam_id . "' 
                        AND person_id = '" . $this->rightPerson->pers_id . "'
                        AND relation_type = 'child'"
                );
            }
        }
    }

    private function updateRelativesQueueSetting()
    {
        // Remove from the relatives-to-merge pairs in the database any pairs that contain the deleted right person
        if (isset($this->relatives_merge)) {
            $temp_rel_arr = explode(";", $this->relatives_merge);
            $new_rel_string = '';
            for ($x = 0; $x < count($temp_rel_arr); $x++) {
                // one array piece is I354@I54. We DONT want to match "I35" or "I5" 
                // so to make sure we find the complete number we look for I354@ or for I345;
                if (
                    strstr($temp_rel_arr[$x], $this->rightPerson->pers_gedcomnumber . "@") === false
                    && strstr($temp_rel_arr[$x] . ";", $this->rightPerson->pers_gedcomnumber . ";") === false
                ) {
                    $new_rel_string .= $temp_rel_arr[$x] . ";";
                }
            }
            $this->relatives_merge = substr($new_rel_string, 0, -1); // take off last ;
            $this->db_functions->update_settings('rel_merge_' . $this->tree_id, $this->relatives_merge);
        }

        if (isset($_SESSION['dupl_arr_' . $this->tree_id])) {
            //remove this pair from the dupl_arr array
            $found1 = $this->leftPerson->pers_id . ';' . $this->rightPerson->pers_id;
            $found2 = $this->rightPerson->pers_id . ';' . $this->leftPerson->pers_id;
            for ($z = 0; $z < count($_SESSION['dupl_arr_' . $this->tree_id]); $z++) {
                if ($_SESSION['dupl_arr_' . $this->tree_id][$z] == $found1 or $_SESSION['dupl_arr_' . $this->tree_id][$z] == $found2) {
                    //unset($_SESSION['dupl_arr'][$z]) ;
                    array_splice($_SESSION['dupl_arr_' . $this->tree_id], $z, 1);
                }
            }
        }
    }

    private function buildResult($mode)
    {
        $results = [];
        if ($mode != 'automatic' && $mode != 'relatives') {
            $name1 = $this->leftPerson->pers_firstname . ' ' . $this->leftPerson->pers_lastname; // store for notification later
            $name2 = $this->rightPerson->pers_firstname . ' ' . $this->rightPerson->pers_lastname; // store for notification later

            $results['name1'] = $name1;
            $results['name2'] = $name2;

            $rela = explode(';', $this->relatives_merge);
            $results['rela'] = count($rela) - 1;
        }
        return $results;
    }

    private function mergeVitalEvents($mode)
    {
        $eventManager = new EventManager($this->dbh, $this->tree_id);

        // PERSONAL DATA
        // default:
        // 1. if there is data for left only, or for left and right --> the left data is retained.
        // 2. if right has data and left hasn't --> right's data is transfered to left
        // in manual, duplicate and relatives merge this can be over-ruled by the admin with the radio buttons

        // for automatic merge see if data has to be transferred from right to left
        // (for manual, duplicate and relative merge this is done in the form with radio buttons by the user)
        $l_name = '1';
        $f_name = '1';
        $b_date = '1';
        $b_place = '1';
        $d_date = '1';
        $d_place = '1';
        $b_time = '1';
        $b_text = '1';
        $d_time = '1';
        $d_text = '1';
        $d_cause = '1';
        $br_date = '1';
        $br_place = '1';
        $br_text = '1';
        $bp_date = '1';
        $bp_place = '1';
        $bp_text = '1';
        $crem = '1';
        $reli = '1';
        $code = '1';
        $stborn = '1';
        $alive = '1';
        $patr = '1';
        $n_text = '1';
        $text = '1';

        if ($mode == 'automatic') {
            // the regular items for automatic mode
            // 2 = move text to left person.
            // 3 = append right text to left text
            if ($this->leftPerson->pers_birth_date == '' && $this->rightPerson->pers_birth_date != '') {
                $b_date = '2';
            }
            if ($this->leftPerson->pers_birth_place == '' && $this->rightPerson->pers_birth_place != '') {
                $b_place = '2';
            }
            if ($this->leftPerson->pers_death_date == '' && $this->rightPerson->pers_death_date != '') {
                $d_date = '2';
            }
            if ($this->leftPerson->pers_death_place == '' && $this->rightPerson->pers_death_place != '') {
                $d_place = '2';
            }
            if ($this->leftPerson->pers_birth_time == '' && $this->rightPerson->pers_birth_time != '') {
                $b_time = '2';
            }
            if ($this->leftPerson->pers_birth_text == '' && $this->rightPerson->pers_birth_text != '') {
                $b_text = '2';
            }
            if ($this->leftPerson->pers_death_time == '' && $this->rightPerson->pers_death_time != '') {
                $d_time = '2';
            }
            if ($this->leftPerson->pers_death_text == '' && $this->rightPerson->pers_death_text != '') {
                $d_text = '2';
            }
            if ($this->leftPerson->pers_death_cause == '' && $this->rightPerson->pers_death_cause != '') {
                $d_cause = '2';
            }
            if ($this->leftPerson->pers_buried_date == '' && $this->rightPerson->pers_buried_date != '') {
                $br_date = '2';
            }
            if ($this->leftPerson->pers_buried_place == '' && $this->rightPerson->pers_buried_place != '') {
                $br_place = '2';
            }
            if ($this->leftPerson->pers_buried_text == '' && $this->rightPerson->pers_buried_text != '') {
                $br_text = '2';
            }
            if ($this->leftPerson->pers_bapt_date == '' && $this->rightPerson->pers_bapt_date != '') {
                $bp_date = '2';
            }
            if ($this->leftPerson->pers_bapt_place == '' && $this->rightPerson->pers_bapt_place != '') {
                $bp_place = '2';
            }
            if ($this->leftPerson->pers_bapt_text == '' && $this->rightPerson->pers_bapt_text != '') {
                $bp_text = '2';
            }
            if ($this->leftPerson->pers_religion == '' && $this->rightPerson->pers_religion != '') {
                $reli = '2';
            }
            if ($this->leftPerson->pers_own_code == '' && $this->rightPerson->pers_own_code != '') {
                $code = '2';
            }
            if ($this->leftPerson->pers_stillborn == '' && $this->rightPerson->pers_stillborn != '') {
                $stborn = '2';
            }
            if ($this->leftPerson->pers_alive == '' && $this->rightPerson->pers_alive != '') {
                $alive = '2';
            }
            if ($this->leftPerson->pers_patronym == '' && $this->rightPerson->pers_patronym != '') {
                $patr = '2';
            }
            if ($this->leftPerson->pers_name_text == '' && $this->rightPerson->pers_name_text != '') {
                $n_text = '2';
            }
            if ($this->leftPerson->pers_text == '' && $this->rightPerson->pers_text != '') {
                $text = '2';
            }
            if ($this->leftPerson->pers_cremation == '' && $this->rightPerson->pers_cremation != '') {
                $crem = '2';
            }
        } else {
            // *** Manual merge ***

            // *** Birth ***
            if (isset($_POST['b_date']) && $_POST['b_date'] == '2') {
                $b_date = '2';
            }
            if (isset($_POST['b_place']) && $_POST['b_place'] == '2') {
                $b_place = '2';
            }
            if (isset($_POST['b_time']) && $_POST['b_time'] == '2') {
                $b_time = '2';
            }
            if (isset($_POST['b_text']) && $_POST['b_text'] == '2') {
                $b_text = '2';
            }
            if (isset($_POST['stborn']) && $_POST['stborn'] == '2') {
                $stborn = '2';
            }
            //isset($_POST["pers_birth_date_hebnight"]

            // *** Baptised ***
            if (isset($_POST['bp_date']) && $_POST['bp_date'] == '2') {
                $bp_date = '2';
            }
            if (isset($_POST['bp_place']) && $_POST['bp_place'] == '2') {
                $bp_place = '2';
            }
            if (isset($_POST['bp_text']) && $_POST['bp_text'] == '2') {
                $bp_text = '2';
            }

            // *** Death ***
            if (isset($_POST['d_date']) && $_POST['d_date'] == '2') {
                $d_date = '2';
            }
            if (isset($_POST['d_place']) && $_POST['d_place'] == '2') {
                $d_place = '2';
            }
            if (isset($_POST['d_text']) && $_POST['d_text'] == '2') {
                $d_text = '2';
            }
            if (isset($_POST['d_time']) && $_POST['d_time'] == '2') {
                $d_time = '2';
            }
            if (isset($_POST['d_cause']) && $_POST['d_cause'] == '2') {
                $d_cause = '2';
            }

            // *** Buried ***
            if (isset($_POST['br_date']) && $_POST['br_date'] == '2') {
                $br_date = '2';
            }
            if (isset($_POST['br_place']) && $_POST['br_place'] == '2') {
                $br_place = '2';
            }
            if (isset($_POST['br_text']) && $_POST['br_text'] == '2') {
                $br_text = '2';
            }
            if (isset($_POST['crem']) && $_POST['crem'] == '2') {
                $crem = '2';
            }
        }

        // *** Update manually selected ($_POST) or automatically selected items ***
        // EXAMPLE: $this->check_regular(MANUAL $_POST variable, AUTO variable, 'pers_lastname');
        $this->check_regular('l_name', $l_name, 'pers_lastname');
        $this->check_regular('f_name', $f_name, 'pers_firstname');
        $this->check_regular('reli', $reli, 'pers_religion');
        $this->check_regular('code', $code, 'pers_own_code');
        $this->check_regular('alive', $alive, 'pers_alive');
        $this->check_regular('patr', $patr, 'pers_patronym');
        $this->check_regular_text('n_text', $n_text, 'pers_name_text');
        $this->check_regular_text('text', $text, 'pers_text');

        // *** Add or update birth event (left person) ***
        // TODO: pers_birth_date_hebnight
        if ($b_date == '2' || $b_place == '2' || $b_time == '2' || $b_text == '2' || $stborn == '2') {
            $birth_event = [
                'tree_id' => $this->leftPerson->pers_tree_id,
                'person_id' => $this->leftPerson->pers_id,
                'event_connect_kind' => 'person',
                'event_connect_id' => $this->leftPerson->pers_gedcomnumber,
                'event_kind' => 'birth',
                'event_event' => '',
                'event_gedcom' => ''
            ];

            if ($b_date == '2') {
                $birth_event['event_date'] = $this->rightPerson->pers_birth_date;
            }
            if ($b_place == '2') {
                $birth_event['event_place'] = $this->rightPerson->pers_birth_place;
            }
            if ($b_time == '2') {
                $birth_event['event_time'] = $this->rightPerson->pers_birth_time;
            }
            if ($b_text == '2') {
                $birth_event['event_text'] = $this->rightPerson->pers_birth_text;
            }
            if ($stborn == '2') {
                $birth_event['stillborn'] = $this->rightPerson->pers_stillborn;
            }
            //'event_date_hebnight' => isset($_POST["pers_birth_date_hebnight"]) ? $_POST["pers_birth_date_hebnight"] : ''

            if (isset($this->leftPerson->pers_birth_event_id)) {
                $birth_event['event_id'] = $this->leftPerson->pers_birth_event_id;
            }
            $eventManager->update_event($birth_event);

            // *** Remove right person birth event ***
            if (isset($this->rightPerson->pers_birth_event_id)) {
                $this->dbh->query("DELETE FROM humo_events WHERE event_id = '" . $this->rightPerson->pers_birth_event_id . "'");
            }
        }

        // *** Add or update baptise event (left person) ***
        if ($bp_date == '2' || $bp_place == '2' || $bp_text == '2') {
            $baptise_event = [
                'tree_id' => $this->leftPerson->pers_tree_id,
                'person_id' => $this->leftPerson->pers_id,
                'event_connect_kind' => 'person',
                'event_connect_id' => $this->leftPerson->pers_gedcomnumber,
                'event_kind' => 'baptism',
                'event_event' => '',
                'event_gedcom' => ''
            ];

            if ($bp_date == '2') {
                $baptise_event['event_date'] = $this->rightPerson->pers_bapt_date;
            }
            if ($bp_place == '2') {
                $baptise_event['event_place'] = $this->rightPerson->pers_bapt_place;
            }
            if ($bp_text == '2') {
                $baptise_event['event_text'] = $this->rightPerson->pers_bapt_text;
            }

            if (isset($this->leftPerson->pers_bapt_event_id)) {
                $baptise_event['event_id'] = $this->leftPerson->pers_bapt_event_id;
            }
            $eventManager->update_event($baptise_event);

            // *** Remove right person baptise event ***
            if (isset($this->rightPerson->pers_bapt_event_id)) {
                $this->dbh->query("DELETE FROM humo_events WHERE event_id = '" . $this->rightPerson->pers_bapt_event_id . "'");
            }
        }

        // *** Add or update death event (left person) ***
        //TODO: pers_death_date_hebnight, pers_death_age
        if ($d_date == '2' || $d_place == '2' || $d_time == '2' || $d_text == '2' || $d_cause == '2') {
            $death_event = [
                'tree_id' => $this->leftPerson->pers_tree_id,
                'person_id' => $this->leftPerson->pers_id,
                'event_connect_kind' => 'person',
                'event_connect_id' => $this->leftPerson->pers_gedcomnumber,
                'event_kind' => 'death',
                'event_event' => '',
                'event_gedcom' => ''
            ];
            if ($d_date == '2') {
                $death_event['event_date'] = $this->rightPerson->pers_death_date;
            }
            if ($d_place == '2') {
                $death_event['event_place'] = $this->rightPerson->pers_death_place;
            }
            if ($d_time == '2') {
                $death_event['event_time'] = $this->rightPerson->pers_death_time;
            }
            if ($d_text == '2') {
                $death_event['event_text'] = $this->rightPerson->pers_death_text;
            }
            if ($d_cause == '2') {
                $death_event['cause'] = $this->rightPerson->pers_death_cause;
            }
            if (isset($this->leftPerson->pers_death_event_id)) {
                $death_event['event_id'] = $this->leftPerson->pers_death_event_id;
            }
            $eventManager->update_event($death_event);
            // *** Remove right person death event ***
            if (isset($this->rightPerson->pers_death_event_id)) {
                $this->dbh->query("DELETE FROM humo_events WHERE event_id = '" . $this->rightPerson->pers_death_event_id . "'");
            }
        }

        // *** Add or update buried event (left person) ***
        // TODO pers_buried_date_hebnight
        if ($br_date == '2' || $br_place == '2' || $br_text == '2' || $crem == '2') {
            $buried_event = [
                'tree_id' => $this->leftPerson->pers_tree_id,
                'person_id' => $this->leftPerson->pers_id,
                'event_connect_kind' => 'person',
                'event_connect_id' => $this->leftPerson->pers_gedcomnumber,
                'event_kind' => 'burial',
                'event_event' => '',
                'event_gedcom' => ''
            ];
            if ($br_date == '2') {
                $buried_event['event_date'] = $this->rightPerson->pers_buried_date;
            }
            if ($br_place == '2') {
                $buried_event['event_place'] = $this->rightPerson->pers_buried_place;
            }
            if ($br_text == '2') {
                $buried_event['event_text'] = $this->rightPerson->pers_buried_text;
            }
            if ($crem == '2') {
                $buried_event['cremation'] = $this->rightPerson->pers_cremation;
            }
            if (isset($this->leftPerson->pers_buried_event_id)) {
                $buried_event['event_id'] = $this->leftPerson->pers_buried_event_id;
            }
            $eventManager->update_event($buried_event);
            // *** Remove right person buried event ***
            if (isset($this->rightPerson->pers_buried_event_id)) {
                $this->dbh->query("DELETE FROM humo_events WHERE event_id = '" . $this->rightPerson->pers_buried_event_id . "'");
            }
        }
    }

    private function mergeOtherEventsAddressesSources($mode)
    {
        // check for posted event, address and source items (separate functions below process input from comparison form)
        if ($mode != 'automatic') {
            // *** Merge events ***
            $skip_events = ["birth", "baptism", "death", "burial"];
            $right_events = $this->dbh->query("SELECT * FROM humo_events
                WHERE (event_connect_kind='person' OR event_kind='ASSO') 
                AND person_id ='" . $this->rightPerson->pers_id . "' 
                AND event_kind NOT IN ('" . implode("','", $skip_events) . "')
                ORDER BY event_kind ");

            // if right has no events it did not appear in the comparison table, so the whole thing is unnecessary
            if ($right_events->rowCount() > 0) {
                while ($right_eventsDb = $right_events->fetch(PDO::FETCH_OBJ)) {
                    $event_shown = false;
                    if (isset($_POST['r_event_shown_' . $right_eventsDb->event_id])) {
                        $event_shown = true;
                    }

                    $event_checked = false;
                    if (isset($_POST['r_event_checked_' . $right_eventsDb->event_id])) {
                        $event_checked = true;
                    }

                    if ($event_shown && $event_checked) {
                        // change right's I to left's I (also change person_id)
                        $this->dbh->query("UPDATE humo_events SET
                            event_connect_id ='" . $this->leftPerson->pers_gedcomnumber . "',
                            person_id = '" . $this->leftPerson->pers_id . "'
                            WHERE event_id ='" . $right_eventsDb->event_id . "'");
                    } elseif ($event_shown) {
                        // clean up database -> remove this entry altogether (if it exists...)
                        $this->dbh->query("DELETE FROM humo_events WHERE event_id ='" . $right_eventsDb->event_id . "'");
                    }
                }

                // If right event exists, and left event isn't selected: remove left event.
                $skip_events = ["birth", "baptism", "death", "burial"];
                $left_events = $this->dbh->query("SELECT * FROM humo_events
                    WHERE (event_connect_kind='person' OR event_kind='ASSO') 
                    AND person_id ='" . $this->leftPerson->pers_id . "' 
                    AND event_kind NOT IN ('" . implode("','", $skip_events) . "')
                    ORDER BY event_kind ");

                while ($left_eventsDb = $left_events->fetch(PDO::FETCH_OBJ)) {
                    $event_shown = false;
                    if (isset($_POST['l_event_shown_' . $left_eventsDb->event_id])) {
                        $event_shown = true;
                    }

                    $event_checked = false;
                    if (isset($_POST['l_event_checked_' . $left_eventsDb->event_id])) {
                        $event_checked = true;
                    }

                    // Left event isn't selected, remove the event.
                    if ($event_shown && $event_checked == false) {
                        $this->dbh->query("DELETE FROM humo_events WHERE event_id ='" . $left_eventsDb->event_id . "'");
                    }
                }
            }

            // *** Merge addresses ***
            $right_address = $this->dbh->query("SELECT * FROM humo_connections
                WHERE connect_tree_id='" . $this->tree_id . "'
                AND LOCATE('address',connect_sub_kind)!=0
                AND connect_connect_id ='" . $this->rightPerson->pers_gedcomnumber . "'");
            if ($right_address->rowCount() > 0) {
                //if right has no addresses it did not appear in the comparison table, so the whole thing is unnecessary
                $left_address = $this->dbh->query("SELECT * FROM humo_connections
                    WHERE connect_tree_id='" . $this->tree_id . "'
                    AND LOCATE('address',connect_sub_kind)!=0
                    AND connect_connect_id ='" . $this->leftPerson->pers_gedcomnumber . "'");
                while ($left_addressDb = $left_address->fetch(PDO::FETCH_OBJ)) {
                    $address_shown = false;
                    if (isset($_POST['l_address_shown_' . $left_addressDb->connect_id])) {
                        $address_shown = true;
                    }

                    $address_checked = false;
                    if (isset($_POST['l_address_checked_' . $left_addressDb->connect_id])) {
                        $address_checked = true;
                    }

                    if ($address_shown && $address_checked == false) {
                        $this->dbh->query("DELETE FROM humo_connections WHERE connect_id ='" . $left_addressDb->connect_id . "'");
                    }
                }

                while ($right_addressDb = $right_address->fetch(PDO::FETCH_OBJ)) {
                    $address_shown = false;
                    if (isset($_POST['r_address_shown_' . $right_addressDb->connect_id])) {
                        $address_shown = true;
                    }

                    $address_checked = false;
                    if (isset($_POST['r_address_checked_' . $right_addressDb->connect_id])) {
                        $address_checked = true;
                    }

                    if ($address_shown && $address_checked) {
                        // change right's I to left's I
                        $this->dbh->query("UPDATE humo_connections SET connect_connect_id ='" . $this->leftPerson->pers_gedcomnumber . "' WHERE connect_id ='" . $right_addressDb->connect_id . "'");
                    } elseif ($address_shown) {
                        // clean up database -> remove this entry altogether (IF IT EXISTS...)
                        $this->dbh->query("DELETE FROM humo_connections WHERE connect_id ='" . $right_addressDb->connect_id . "'");
                    }
                }
            }

            // *** Merge sources ***
            $right_source = $this->dbh->query("SELECT * FROM humo_connections
                WHERE connect_tree_id='" . $this->tree_id . "'
                AND LOCATE('source',connect_sub_kind)!=0
                AND connect_connect_id ='" . $this->rightPerson->pers_gedcomnumber . "'");
            if ($right_source->rowCount() > 0) {
                //if right has no sources it did not appear in the comparison table, so the whole thing is unnecessary
                $left_source = $this->dbh->query("SELECT * FROM humo_connections
                    WHERE connect_tree_id='" . $this->tree_id . "'
                    AND LOCATE('source',connect_sub_kind)!=0
                    AND connect_connect_id ='" . $this->leftPerson->pers_gedcomnumber . "'");
                while ($left_sourceDb = $left_source->fetch(PDO::FETCH_OBJ)) {
                    $source_shown = false;
                    if (isset($_POST['l_source_shown_' . $left_sourceDb->connect_id])) {
                        $source_shown = true;
                    }

                    $source_checked = false;
                    if (isset($_POST['l_source_checked_' . $left_sourceDb->connect_id])) {
                        $source_checked = true;
                    }

                    if ($source_shown && $source_checked == false) {
                        $this->dbh->query("DELETE FROM humo_connections WHERE connect_id ='" . $left_sourceDb->connect_id . "'");
                    }
                }

                while ($right_sourceDb = $right_source->fetch(PDO::FETCH_OBJ)) {
                    $source_shown = false;
                    if (isset($_POST['r_source_shown_' . $right_sourceDb->connect_id])) {
                        $source_shown = true;
                    }

                    $source_checked = false;
                    if (isset($_POST['r_source_checked_' . $right_sourceDb->connect_id])) {
                        $source_checked = true;
                    }

                    if ($source_shown && $source_checked) {
                        // change right's I to left's I
                        $this->dbh->query("UPDATE humo_connections SET connect_connect_id ='" . $this->leftPerson->pers_gedcomnumber . "' WHERE connect_id ='" . $right_sourceDb->connect_id . "'");
                    } elseif ($source_shown) {
                        // clean up database -> remove this entry altogether (IF IT EXISTS...)
                        $this->dbh->query("DELETE FROM humo_connections WHERE connect_id ='" . $right_sourceDb->connect_id . "'");
                    }
                }
            }
        } else {
            // for automatic mode check for situation where right has event/source/address data and left not. In that case use right's.
            $right_result = $this->dbh->query("SELECT * FROM humo_events WHERE person_id ='" . $this->rightPerson->pers_id . "'");
            while ($right_resultDb = $right_result->fetch(PDO::FETCH_OBJ)) {
                $left_result = $this->dbh->query("SELECT * FROM humo_events WHERE person_id ='" . $this->leftPerson->pers_id . "'");
                $foundleft = false;
                while ($left_resultDb = $left_result->fetch(PDO::FETCH_OBJ)) {
                    if ($left_resultDb->event_kind == $right_resultDb->event_kind && $left_resultDb->event_gedcom == $right_resultDb->event_gedcom) {
                        // NOTE: if "event" or "name" we also check for sub-type (_AKAN, _HEBN, BARM etc) so as not to match different subtypes
                        // this event from right wil not be copied to left - left already has this type event
                        // so clear the database
                        $this->dbh->query("DELETE FROM humo_events WHERE event_id ='" . $right_resultDb->event_id . "'");
                        $foundleft = true;
                    }
                }
                if ($foundleft == false) {
                    // left has no such type of event, so change right's I for left I at this event
                    $this->dbh->query("UPDATE humo_events
                        SET event_connect_id ='" . $this->leftPerson->pers_gedcomnumber . "', person_id = '" . $this->leftPerson->pers_id . "'
                        WHERE event_id ='" . $right_resultDb->event_id . "'");
                }
            }

            // Do same for sources and addresses (from connections table). No need here to differentiate between sources and addresses, all will be handled
            $right_result = $this->dbh->query("SELECT * FROM humo_connections WHERE connect_tree_id='" . $this->tree_id . "' AND connect_connect_id ='" . $this->rightPerson->pers_gedcomnumber . "'");
            while ($right_resultDb = $right_result->fetch(PDO::FETCH_OBJ)) {
                $left_result = $this->dbh->query("SELECT * FROM humo_connections WHERE connect_tree_id='" . $this->tree_id . "' AND connect_connect_id ='" . $this->leftPerson->pers_gedcomnumber . "'");
                $foundleft = false;
                while ($left_resultDb = $left_result->fetch(PDO::FETCH_OBJ)) {
                    if ($left_resultDb->connect_sub_kind == $right_resultDb->connect_sub_kind) {
                        // NOTE: We check for sub-kind so as not to match different sub_kinds
                        // this source/address sub_kind from right will not be copied to left - left already has a source/address for this sub_kind
                        // so clear right's data from the database
                        $this->dbh->query("DELETE FROM humo_connections WHERE connect_id ='" . $right_resultDb->connect_id . "'");
                        $foundleft = true;
                    }
                }
                if ($foundleft == false) {
                    // left has no such sub_kind of source/address, so change right's I for left I at this sub_kind
                    $this->dbh->query("UPDATE humo_connections SET connect_connect_id ='" . $this->leftPerson->pers_gedcomnumber . "' WHERE connect_id ='" . $right_resultDb->connect_id . "'");
                }
            }
        }
    }

    private function cleanupRightPersonReferences()
    {
        // Substract 1 person from the number of persons counter in the family tree.
        $sql = "UPDATE humo_trees SET tree_persons=tree_persons-1 WHERE tree_id='" . $this->tree_id . "'";
        $this->dbh->query($sql);

        // Nov. 2025: New added.
        $qry = "DELETE FROM humo_relations_persons WHERE person_id = '" . $this->rightPerson->pers_id . "'";
        $this->dbh->query($qry);

        // CLEANUP: delete this person's I from any other tables that refer to this person
        // *** TODO 2021: address_connect_xxxx is no longer in use. Will be removed later ***
        $qry = "DELETE FROM humo_addresses WHERE address_tree_id='" . $this->tree_id . "' AND address_connect_sub_kind='person' AND address_connect_id ='" . $this->rightPerson->pers_gedcomnumber . "'";
        $this->dbh->query($qry);

        $qry = "DELETE FROM humo_connections WHERE connect_tree_id='" . $this->tree_id . "' AND connect_connect_id ='" . $this->rightPerson->pers_gedcomnumber . "'";
        $this->dbh->query($qry);

        $qry = "DELETE FROM humo_events WHERE person_id ='" . $this->rightPerson->pers_id . "'";
        $this->dbh->query($qry);

        // CLEANUP: This person's I may still exist in the humo_events table under "event_event" (in event_connect_id2 field),
        // in case of birth/death declaration or bapt/burial witness. If so, change the GEDCOM to the left person's I:
        $qry = "UPDATE humo_events
            SET event_connect_id2 = '" . $this->leftPerson->pers_gedcomnumber . "'
            WHERE event_tree_id='" . $this->tree_id . "'
            AND event_connect_id2 ='" . $this->rightPerson->pers_gedcomnumber . "'";
        $this->dbh->query($qry);

        // Delete right person from humo_persons table
        $qry = "DELETE FROM humo_persons WHERE pers_id ='" . $this->rightPerson->pers_id . "'";
        $this->dbh->query($qry);
    }

    // *** Nov. 2025 refactor functions ***
    private function loadPersons(int $leftId, int $rightId): array
    {
        $left = $this->db_functions->get_person_with_id($leftId);
        $right = $this->db_functions->get_person_with_id($rightId);

        if (!$left || !$right) {
            throw new \RuntimeException('Person not found for merge.');
        }

        // Preload vital events (birth, baptism, death, burial) to set event_id shortcuts
        //$this->enrichWithEventIds($left);
        //$this->enrichWithEventIds($right);

        return [$left, $right];
    }

    private function loadRelations(): array
    {
        $leftRelations = $this->db_functions->get_relations($this->leftPerson->pers_id);
        $rightRelations = $this->db_functions->get_relations($this->rightPerson->pers_id);

        return [$leftRelations, $rightRelations];
    }


    /**
     * function check_regular checks if data from the humo_person table was marked (checked) in the comparison table
     */
    private function check_regular($post_var, $auto_var, $mysql_var)
    {
        if (isset($_POST[$post_var]) && $_POST[$post_var] == '2' || $auto_var == '2') {
            $qry = "UPDATE humo_persons SET " . $mysql_var . " = '" . $this->rightPerson->$mysql_var . "' WHERE pers_id ='" . $this->leftPerson->pers_id . "'";
            $this->dbh->query($qry);
        }
    }

    /**
     * function check_regular_text checks if text data from the humo_person table was marked (checked) in the comparison table
     */
    private function check_regular_text($post_var, $auto_var, $mysql_var)
    {
        if (isset($_POST[$post_var . '_r']) || $auto_var == '2') {
            if (isset($_POST[$post_var . '_l'])) {
                // when not in automatic mode, this means we have to join the notes of left and right
                // If left or right has a @N34@ text entry we join the text as regular text.
                // We can't change the notes in humoX_texts because they could be used for other persons!
                if (substr($this->leftPerson->$mysql_var, 0, 2) === '@N') {
                    $noteqry = $this->dbh->query("SELECT text_text FROM humo_texts WHERE text_tree_id='" . $this->tree_id . "' AND text_gedcomnr = '" . substr($this->leftPerson->$mysql_var, 1, -1) . "'");
                    $noteqryDb = $noteqry->fetch(PDO::FETCH_OBJ);
                    $leftnote = $noteqryDb->text_text;
                } else {
                    $leftnote = $this->leftPerson->$mysql_var;
                }
                if (substr($this->rightPerson->$mysql_var, 0, 2) === '@N') {
                    $noteqry = $this->dbh->query("SELECT text_text FROM humo_texts WHERE text_tree_id='" . $this->tree_id . "' AND text_gedcomnr = '" . substr($this->rightPerson->$mysql_var, 1, -1) . "'");
                    $noteqryDb = $noteqry->fetch(PDO::FETCH_OBJ);
                    $rightnote = $noteqryDb->text_text;
                } else {
                    $rightnote = $this->rightPerson->$mysql_var;
                }
                $qry = "UPDATE humo_persons SET " . $mysql_var . " = CONCAT('" . $leftnote . "',\"\n\",'" . $rightnote . "') WHERE pers_id ='" . $this->leftPerson->pers_id . "'";
            } else {
                $qry = "UPDATE humo_persons SET " . $mysql_var . " = '" . $this->rightPerson->$mysql_var . "' WHERE pers_id ='" . $this->leftPerson->pers_id . "'";
            }
            $this->dbh->query($qry);
        }
    }
}
