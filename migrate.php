<?php
// MIT License
//
// Copyright (c) 2017 Sri Harsha Chilakapati
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.

require_once 'vendor/autoload.php';
include_once 'settings.php';

use s9e\TextFormatter\Bundles\Forum as TextFormatter;
use GuzzleHttp\Client;

try
{
    // Create the connections to both the databases, the original for SMF and the second for Flarum
    $smf = new PDO('mysql:host=localhost;dbname=' . smf_dbname, smf_user, smf_pass, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
    $fla = new PDO('mysql:host=localhost;dbname=' . fla_dbname, fla_user, fla_pass, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));

    $smf->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $fla->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $api = new GuzzleHttp\Client([
        'base_uri' => fla_api_url,
        'headers'  => [
            'User-Agent'   => 'SMF-Flarum-Migrate',
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization'=> 'Token '.fla_api_token,
        ]
    ]);

    // API test
    // $response = $api->request('GET', 'discussions');
    // $response = $api->request('GET', 'users/1');
    // var_dump(json_decode($response->getBody(), true));

    // Start the migration process
    if (confirm("categories")) migrateCategories($smf, $fla, $api);
    if (confirm("boards"))     migrateBoards($smf, $fla, $api);
    if (confirm("users"))      migrateUsers($smf, $fla, $api);
    if (confirm("posts"))      migratePosts($smf, $fla, $api);
}
catch (PDOException $e)
{
    echo $e->getMessage();
}

/**
 * Function to migrate the categories from the SMF forum to the Flarum database. It considers the categories as the first
 * level tags, meaning that the first level tags should be selected for all the discussions.
 */
function migrateCategories($smf, $fla, $api)
{
    // Query the existing categories from the SMF backend
    $stmt = $smf->query('SELECT * FROM `smf_categories` ORDER BY catOrder');
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    // Delete all existing tags from the Flarum forum and reset the AUTO_INCREMENT count for the table
    $fla->exec('DELETE FROM `flarumtags`');
    $fla->exec('ALTER TABLE `flarumtags` AUTO_INCREMENT = 1');

    // Insert statement to insert the tags into the table
    $insert = $fla->prepare('INSERT INTO `flarumtags` (name, slug, description, color, position, icon) VALUES (?, ?, ?, ?, ?, ?)');

    // Counters for calculating the percentages
    $total = $smf->query('SELECT COUNT(*) FROM `smf_categories`')->fetchColumn();
    $done = 0;

    // For each category in the SMF backend
    while ($row = $stmt->fetch())
    {
        // Display percentage
        $done++;
        echo "Migrating categories: " . $done . "/" . $total . " (" . ((int) ($done / $total * 100)) . "%)\r";

        // Transform the data to new record format

        // Colors: https://mycolor.space/?hex=%2300B6FF&sub=1
        // DVDnarr red #b83a17
        switch ($row->ID_CAT) {
            case 6:
                $catColor = '#394955';
                $catDesc = 'Ankündigungen, Hinweise & Tipps rund ums Forum';
                $catOrder = 0;
                $catIcon = 'fas fa-comment-dots';
                break;
            case 4:
                // $catName = 'DVD, Blu-ray & 4K';
                $catColor = '#00b6ff';
                $catDesc = 'News & Infos zu allem rund um DVD, Blu-ray und Ultra-HD Blu-ray.';
                $catOrder = 1;
                $catIcon = 'fas fa-compact-disc';
                break;
            case 1:
                // $catName = 'Kino & TV';
                // $catColor = '#0091d7';
                $catColor = '#394955';
                $catDesc = 'News, Berichte und allgemeines Gequassel über Filme und die Leute, die sie machen.';
                $catOrder = 2;
                $catIcon = 'fas fa-ticket-alt';
                break;
            case 9:
                // $catColor = '#006db0';
                $catColor = '#1d5fb5';
                $catDesc = 'Besprechungen und Diskussionen über Filme und Serien sowie kurze Reviews.';
                $catOrder = 3;
                $catIcon = 'fas fa-file-alt';
                break;
            case 7:
                // $catName = 'Hardware & Heimkino';
                // $catColor = '#004c8a';
                $catColor = '#21569c';
                $catDesc = 'Alles rund um Player, Verstärker, Lautsprecher und das eigene Heimkino.';
                $catOrder = 4;
                $catIcon = 'fas fa-tv';
                break;
            case 5:
                // $catName = 'Off-topic';
                $catColor = '#002d66';
                $catDesc = 'Abseits von bewegten Bildern!';
                $catOrder = 5;
                $catIcon = 'fas fa-quote-right';
                break;
            case 8:
                // $catColor = '#41efb5';
                $catColor = '#006b61';
                $catDesc = 'Schnäppchen & private Angebote sowie Gesuche.';
                $catOrder = 6;
                $catIcon = 'fas fa-shopping-bag';
                break;
            case 3:
                // $catName = 'Mods & Admin';
                // $catColor = '#b7548e';
                $catColor = '#ff0037';
                $catDesc = 'Nur für Mods & Admins sichtbar!';
                $catOrder = 7;
                $catIcon = 'fas fa-user-lock';
                break;
            default:
                $catColor = '#cccccc';
                $catDesc = '';
                $catOrder = $row->catOrder;
                $catIcon = '';
        }

        $data = array(
            preg_replace('(\\&amp;)', '&', $row->name),
            slugify(preg_replace('(\\&amp;)', '&', $row->name)),
            $catDesc,
            $catColor,
            $catOrder,
            $catIcon
        );

        // Insert the new tag into Flarum
        $insert->execute($data);
    }

    echo "\n";
}

/**
 * Function to migrate boards from the SMF backend to the Flarum backend. Since Flarum doesn't support boards and categories,
 * this function translates them to tags. Each board is generated as a second order tag (child of first order tags, which
 * correspond to the categories) and the child boards are generated as secondary tags.
 */
function migrateBoards($smf, $fla, $api)
{
    // Create a helper table for all the boards, which stores the SMF board ids with the Flarum equivalents
    $sql = <<<SQL
        CREATE TABLE IF NOT EXISTS `migrated_boards` (
            smf_board_id INT(10),
            smf_category_id INT(10),
            fla_tag_id INT(10),
            fla_tag_parent_id INT(10)
        );
SQL;
    $smf->exec($sql);
    $smf->exec('TRUNCATE TABLE `migrated_boards`');

    // Statement to insert the migrated boards id into the helper table
    $sql = "INSERT INTO `migrated_boards` (smf_board_id, smf_category_id, fla_tag_id, fla_tag_parent_id) VALUES (:smf_board_id, :smf_category_id, :fla_tag_id, :fla_tag_parent_id);";
    $insert_mb = $smf->prepare($sql);

    // The query to find all the boards in the SMF forum
    $sql = <<<SQL
        SELECT smf_boards.ID_BOARD, smf_boards.ID_CAT, smf_boards.name AS bname, smf_categories.name AS cname, smf_boards.description,
        smf_boards.childLevel, smf_boards.boardOrder FROM smf_boards
        LEFT JOIN smf_categories ON smf_boards.ID_CAT=smf_categories.ID_CAT
        ORDER BY smf_boards.ID_PARENT, smf_boards.boardOrder;
SQL;

    $stmt = $smf->query($sql);
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    // The query to insert the new transformed tags
    $insert = $fla->prepare('INSERT INTO `flarumtags` (name, slug, description, color, position, parent_id, icon) VALUES (?, ?, ?, ?, ?, ?, ?)');

    // Counters to display the progress info
    $total = $smf->query('SELECT COUNT(*) FROM `smf_boards`')->fetchColumn();
    $done = 0;

    // For each board in the SMF backend
    while ($row = $stmt->fetch()) {
        // Display the updated percentage
        $done++;
        echo "Migrating boards: " . $done . "/" . $total . " (" . ((int) ($done / $total * 100)) . "%)\r";

        // childLevel is 0 for first hand boards
        if ($row->childLevel === "0") {
            // Get the category it is associated to, to set the parent relationship
            $stmt2 = $fla->query('SELECT * FROM `flarumtags` WHERE slug=\'' . slugify($row->cname) . '\'');
            $stmt2->setFetchMode(PDO::FETCH_OBJ);
            $row2 = $stmt2->fetch();

            // Compute the new record to be inserted
            $data = array(
                preg_replace('(\\&amp;)', '&', $row->bname),
                slugify($row2->name.'-'.$row->bname),
                $row->description,
                $row2->color,
                $row->boardOrder,
                $row2->id,
                $row2->icon
            );

            // Insert the new record into the database
            $insert->execute($data);
        } else {
            // All the child boards are secondary tags
            // Compute the new record as secondary tag
            $data = array(
                preg_replace('(\\&amp;)', '&', $row->bname),
                slugify($row->bname),
                $row->description,
                '#bdc3c7',
                NULL,
                NULL
            );

            // Insert the tag computed into the database
            $insert->execute($data);
        }

        // Insert the helper record into the helper table
        $insert_mb->execute(array(
            ':smf_board_id' => $row->ID_BOARD,
            ':smf_category_id' => $row->ID_CAT,
            ':fla_tag_id' => $fla->lastInsertId(),
            ':fla_tag_parent_id' => ($row2->id != NULL ? $row2->id : NULL)
        ));
    }

    echo "\n";
}

/**
 * Function to migrate the users from the SMF forum to the new Flarum backend. This function is also responsible to translate
 * the member groups from the old forum to the new forum and associate them in the new forum. However the post stats and
 * the profile picture is not migrated. The users will also be migrated without a password, and hence they are required to
 * click on the forgot password link and generate a new password.
 */
function migrateUsers($smf, $fla, $api)
{
    // Clear existing users from the forum except for the admin account created while installing Flarum.
    // Also reset the AUTO_INCREMENT values for the tables.
    $fla->exec('DELETE FROM `flarumusers` WHERE id != 1');
    $fla->exec('ALTER TABLE `flarumusers` AUTO_INCREMENT = 2');
    $fla->exec('DELETE FROM `flarumgroup_user` WHERE user_id != 1');
    $fla->exec('ALTER TABLE `flarumgroup_user` AUTO_INCREMENT = 2');

    // Create a helper table for all the users, which stores the SMF user ids with the Flarum equivalents
    $sql = <<<SQL
        CREATE TABLE IF NOT EXISTS `migrated_users` (
            smf_id INT(10),
            fla_id INT(10)
        );
SQL;
    $smf->exec($sql);
    $smf->exec('TRUNCATE TABLE `migrated_users`');

    // Statement to insert the migrated user id into the helper table
    $sql = "INSERT INTO `migrated_users` (smf_id, fla_id) VALUES (?, ?);";
    $insert_helper = $smf->prepare($sql);

    // The query to select the existing users from the SMF backend
    $sql = <<<SQL
        SELECT ID_MEMBER, memberName, emailAddress, dateRegistered, lastLogin, personalText, is_activated, ID_GROUP
        FROM `smf_members` ORDER BY ID_MEMBER ASC
SQL;
    $stmt = $smf->query($sql);
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    // Counters for displaying the progress info
    $total = $smf->query('SELECT COUNT(*) FROM `smf_members`')->fetchColumn();
    $done = 0;

    // The query to insert the new users into the Flarum database
    $sql = <<<SQL
        INSERT INTO `flarumusers` (id, username, email, is_email_confirmed, password, bio, joined_at, last_seen_at)
        VALUES (
            ?, ?, ?, ?, '', ?, ?, ?
        );
SQL;
    $insert = $fla->prepare($sql);
    $insert2 = $fla->prepare('INSERT INTO `flarumgroup_user` (user_id, group_id) VALUES (?, ?)');

    // The groupMap. It is a mapping from the SMF member groups to the Flarum groups. The following are present in the
    // original SMF backend.
    //
    // +----------+--------------------+-------------+----------+-------------+------------------+
    // | ID_GROUP | groupName          | onlineColor | minPosts | maxMessages | stars            |
    // +----------+--------------------+-------------+----------+-------------+------------------+
    // |        1 | Administrator      | #FF0000     |       -1 |           0 | 1#rang_admin.gif |
    // |        2 | Globaler Moderator | #6394B5     |       -1 |           0 | 1#rang_gmod.gif  |
    // |        3 | Moderator          | #FF9933     |       -1 |           0 | 1#rang_mod.gif   |
    // |        4 | Raubkopie          |             |        0 |           0 | 1#rang3.gif      |
    // |        9 | Single-Disc        |             |       25 |           0 | 1#rang5.gif      |
    // |        5 | Kinothekar         | #66C12C     |       -1 |           0 | 1#rang_team.gif  |
    // |       10 | Steelbook          |             |      100 |           0 | 1#rang6.gif      |
    // |       11 | Special Edition    |             |     5000 |           0 | 1#rang7.gif      |
    // |       12 | Limited Edition    |             |    10000 |           0 | 1#rang8.gif      |
    // |       13 | Boardmoderator     | #996633     |       -1 |           0 | 1#rang_mod2.gif  |
    // |       15 | Newsposter         |             |       -1 |           0 | 1#rang_news.gif  |
    // |       16 | Podcaster          |             |       -1 |           0 | 1#rang_news.gif  |
    // |       17 | Digital-Copy       |             |        1 |           0 | 1#rang4.gif      |
    // +----------+--------------------+-------------+----------+-------------+------------------+
    //
    // But the Flarum has only four groups. For this sake, we are converting each and every user into the following four
    // groups which are supported by Flarum. Though new groups can be added to the Flarum, it requires customized extensions
    // to take care of all the groups and recalculate them after each user does a post.
    //
    // +----+---------------+-------------+---------+---------------+-----------+
    // | id | name_singular | name_plural | color   | icon          | is_hidden |
    // +----+---------------+-------------+---------+---------------+-----------+
    // |  1 | Admin         | Admins      | #B72A2A | fas fa-wrench |         0 |
    // |  2 | Guest         | Guests      | NULL    | NULL          |         0 |
    // |  3 | Member        | Members     | NULL    | NULL          |         0 |
    // |  4 | Mod           | Mods        | #80349E | fas fa-bolt   |         0 |
    // +----+---------------+-------------+---------+---------------+-----------+
    //
    // This map exists to ease the conversion from one table to another. Additionally some members in the SMF backend
    // had the ID_GROUP attribute set to 0, which is not documented in the SMF sources. Hence this script considers everyone
    // else as the regular members.
    $groupMap = array(
        0 => 3,
        1 => 1,
        2 => 4,
        3 => 4,
        4 => 3,
        9 => 3,
        5 => 3,
        10 => 3,
        11 => 3,
        12 => 3,
        13 => 3,
        15 => 3,
        16 => 3,
        17 => 3
    );

    // $userId = 2;

    // For each user in the SMF database
    while ($row = $stmt->fetch())
    {
        // Update and display the progess info
        $done++;
        echo "Migrating users: " . $done . "/" . $total . " (" . ((int) ($done / $total * 100)) . "%)\r";

        // use original SMF Member ID
        $userId = $row->ID_MEMBER;

        // Transform the user to the Flarum table
        $data = array(
            $userId,
            $row->memberName,
            $row->emailAddress === "" ? "email-user-id-" . $row->ID_MEMBER . "@example.com" : $row->emailAddress,
            $row->is_activated !== "0" ? 1 : 0,
            $row->personalText,
            convertTimestamp($row->dateRegistered),
            convertTimestamp($row->lastLogin)
        );

        try
        {
            // Insert the user into the database
            $insert->execute($data);

            // Compute the group record for this user
            $data = array(
                $userId,
                $groupMap[(int) $row->ID_GROUP]
            );

            try
            {
                // Insert the group record into the database
                $insert2->execute($data);

                // Insert the helper record into the helper table
                $insert_helper->execute(array($row->ID_MEMBER, $userId));
            }
            catch (Exception $e)
            {
                echo "Error while updating member group for the following user:\n";
                var_dump($data);
                echo "The message was: " . $e->getMessage() . "\n";
            }
        }
        catch (Exception $e)
        {
            echo "Error while porting the following user:\n";
            var_dump($row);
            echo "The message was: " . $e->getMessage() . "\n";
        }
        finally
        {
            // we use the original SMF Member IDs!
            // $userId++;
        }

        // Avatar
        $avatar = array();
        $avatar = $smf->query('SELECT * FROM `smf_attachments` WHERE ID_MEMBER = '.$userId)->fetchAll();
        if (count($avatar) > 0 && $userId != 739) {
            // echo $userId."\n";
            // var_dump($avatar);
            // echo "https://www.dvdnarr.com/community/index.php?action=dlattach;attach=".$avatar[0]['ID_ATTACH'].";type=avatar \n";
            $avatarUrl = smf_url."community/index.php?action=dlattach;attach=".$avatar[0]['ID_ATTACH'].";type=avatar";

            if (file_put_contents('/tmp/avatarmigration', fopen($avatarUrl, 'r'))) {
                $response = $api->request(
                    'POST',
                    'users/' . $userId . '/avatar',
                    [
                        'multipart' => [
                            [
                                'name' => 'avatar',
                                'contents' => fopen('/tmp/avatarmigration', 'r'),
                                'filename' => 'avatar-migrated-from-smf.png'
                            ]
                        ]
                    ]
                );
                // var_dump(json_decode($response->getBody(), true));
            }
        }
        // if (count($avatar) > 1) {
        //     var_dump($avatar);
        //     exit;
        // }
    }

    echo "\n";
}

/**
 * Function to migrate the posts from the SMF backend to the new Flarum backend. Uses the default bundle to transform
 * the posts data from BBCode into the data format of Flarum.
 */
function migratePosts($smf, $fla, $api)
{
    // Clear existing posts from the forum. Also reset the AUTO_INCREMENT values for the tables.
    $fla->exec('DELETE FROM `flarumposts`');
    $fla->exec('ALTER TABLE `flarumposts` AUTO_INCREMENT = 1');
    $fla->exec('DELETE FROM `flarumdiscussions`');
    $fla->exec('ALTER TABLE `flarumdiscussions` AUTO_INCREMENT = 1');
    $fla->exec('DELETE FROM `flarumdiscussion_tag`');
    $fla->exec('ALTER TABLE `flarumdiscussion_tag` AUTO_INCREMENT = 1');

    // SQL query to fetch the topics from the JGO database
    $sql = <<<SQL
        SELECT
            t.ID_TOPIC, t.ID_MEMBER_STARTED, t.ID_BOARD, m.subject, m.posterTime, m.posterName, t.numReplies, t.locked, t.isSticky,
            u.fla_id
        FROM
            `smf_topics` t
        LEFT JOIN
            `smf_messages` m ON t.ID_FIRST_MSG = m.ID_MSG
        LEFT JOIN
            `migrated_users` u ON t.ID_MEMBER_STARTED = u.smf_id
        ORDER BY m.posterTime, t.ID_TOPIC
SQL;
    $topics = $smf->query($sql);
    $topics->setFetchMode(PDO::FETCH_OBJ);

    // Counters for displaying the progress info
    $total = $smf->query('SELECT COUNT(*) FROM `smf_topics`')->fetchColumn();
    $done = 0;

    // SQL statement to insert the topic into the Flarum backend
    $sql = <<<SQL
        INSERT INTO `flarumdiscussions` (
            id, title, comment_count, post_number_index, created_at, user_id, slug, is_locked, is_sticky
        ) VALUES (
            :id, :title, :comment_count, :post_number_index, :created_at, :user_id, :slug, :is_locked, :is_sticky
        );
SQL;
    $insert_topic = $fla->prepare($sql);

    // SQL statement to update the topic into the Flarum backend
    $sql = <<<SQL
        UPDATE `flarumdiscussions` SET
            participant_count = :participant_count,
            first_post_id = :first_post_id,
            last_post_id = :last_post_id,
            last_post_number = :last_post_number,
            last_posted_at = :last_posted_at,
            last_posted_user_id = :last_posted_user_id
        WHERE id = :id;
SQL;
    $update_topic = $fla->prepare($sql);

    // SQL statement to bind discussion to tag
    $sql = <<<SQL
        INSERT INTO `flarumdiscussion_tag` (`discussion_id`, `tag_id`) VALUES (:discussion_id, :tag_id);
SQL;
    $insert_discussion_tag = $fla->prepare($sql);

    // // Mapping Array from SMF Category id to Flarum Tag id
    $map_category_tag = array();
    $migrated_boards = $smf->query('SELECT * FROM `migrated_boards`');
    $migrated_boards->setFetchMode(PDO::FETCH_OBJ);
    while ($migrated_board = $migrated_boards->fetch()) {
        $map_category_tag[$migrated_board->fla_tag_id] = $migrated_board->fla_tag_parent_id;
    }
    // var_dump($map_category_tag);

    // Mapping Array from SMF Board id to Flarum Tag id
    $map_board_tag = array();
    $migrated_boards = $smf->query('SELECT * FROM `migrated_boards`');
    $migrated_boards->setFetchMode(PDO::FETCH_OBJ);
    while ($migrated_board = $migrated_boards->fetch()) {
        $map_board_tag[$migrated_board->smf_board_id] = $migrated_board->fla_tag_id;
    }
    // var_dump($map_board_tag);

    // SQL statement to insert the post into the Flarum backend
    $sql = <<<SQL
        INSERT INTO `flarumposts` (
            `id`, `discussion_id`, `type`, `number`, `created_at`, `user_id`, `content`
        ) VALUES (
            :id, :discussion_id, 'comment', :number, :created_at, :user_id, :content
        );
SQL;
    $insert_post = $fla->prepare($sql);

    // preg_replace("/&quot;/", '"', $topic->subject)
    // preg_replace('(\\&amp;)', '&', $row->bname)
    $search = array();
    $search[0] = '/&quot;/';
    $search[1] = '/&amp;/';
    $replace = array();
    $replace[0] = '"';
    $replace[1] = '&';

    // Migrate Topics
    while ($topic = $topics->fetch()) {
        // Update and display the progess info
        $done++;
        echo "\033[2K\r"; // clear line
        echo "Migrating topic " . $done . "/" . $total . " (" . ((int) ($done / $total * 100)) . "%) // ID: ". $topic->ID_TOPIC . " Posts: " . ($topic->numReplies + 1) . " Slug: " . slugify($topic->subject) . "\r";

        $sql = <<<SQL
            SELECT m.ID_MSG, m.body, m.posterTime, u.fla_id
            FROM `smf_messages` m
            LEFT JOIN `migrated_users` u ON m.ID_MEMBER = u.smf_id
            WHERE m.ID_TOPIC = {$topic->ID_TOPIC}
            ORDER BY m.posterTime
SQL;

        $posts = $smf->query($sql);
        $posts->setFetchMode(PDO::FETCH_OBJ);

        $first_post_id = NULL;
        $last_post_id = NULL;
        $post_counter = 1;
        $participants = array();

        $data = array(
            ':id' => $topic->ID_TOPIC,
            ':title' => preg_replace($search, $replace, $topic->subject),
            ':comment_count' => $topic->numReplies,
            ':post_number_index' => $topic->numReplies + 1,
            ':created_at' => convertTimestamp($topic->posterTime),
            ':user_id' => $topic->fla_id,
            ':slug' => slugify($topic->subject),
            ':is_locked' => $topic->locked,
            ':is_sticky' => $topic->isSticky,
        );
        $insert_topic->execute($data);
        // $insert_topic->debugDumpParams();

        // Tie discussion to tagS
        // former category
        $insert_discussion_tag->execute(array(':discussion_id' => $topic->ID_TOPIC, ':tag_id' => $map_category_tag[$map_board_tag[$topic->ID_BOARD]]));
        // former board
        $insert_discussion_tag->execute(array(':discussion_id' => $topic->ID_TOPIC, ':tag_id' => $map_board_tag[$topic->ID_BOARD]));
        // $insert_discussion_tag->debugDumpParams();

        // Migrate Posts
        while ($post = $posts->fetch())
        {
            $data = array(
                ':id' => $post->ID_MSG,
                ':discussion_id' => $topic->ID_TOPIC,
                ':number' => $post_counter++,
                ':created_at' => convertTimestamp($post->posterTime),
                ':user_id' => $post->fla_id,
                ':content' => TextFormatter::parse(replaceBodyStrings($post->body))
            );
            $insert_post->execute($data);
            // $insert_post->debugDumpParams();

            $participants[] = $post->fla_id;
            if ($first_post_id === NULL) {
                $first_post_id = $post->ID_MSG;
            }
            $last_post_id = $post->ID_MSG;
            $last_post_number = $post_counter;
            $last_posted_at = convertTimestamp($post->posterTime);
            $last_posted_user_id = $post->fla_id;
        }

        $data = array(
            ':participant_count' => count(array_unique($participants)),
            ':first_post_id' => $first_post_id,
            ':last_post_id' => $last_post_id,
            ':last_post_number' => $last_post_number,
            ':last_posted_at' => $last_posted_at,
            ':last_posted_user_id' => $last_posted_user_id,
            ':id' => $topic->ID_TOPIC
        );
        $update_topic->execute($data);
        // $update_topic->debugDumpParams();

        // Limit Migration for Testing
        if ($topic->ID_TOPIC >= 500) {
            echo "\n";
            return;
        }
    }

    echo "\n";
}

/**
 * Utility function to replace some characters prior to storing in Flarum.
 */
function replaceBodyStrings($str)
{
    $str = preg_replace("/\<br\>/", "\n", $str);
    $str = preg_replace("/\<br\s*\/\>/", "\n", $str);
    $str = preg_replace("/&nbsp;/", " ", $str);
    $str = preg_replace("/&quot;/", "\"", $str);
    $str = preg_replace("/&lt;/", "<", $str);
    $str = preg_replace("/&gt;/", ">", $str);
    return preg_replace("/\[quote\][\s\t\r\n]*\[\/quote\]/", "", $str);
}

/**
 * Utility function to compute slugs for topics, categories and boards.
 */
function slugify($text)
{
    // echo $text." ---> ";

    ///////////////////////////////////////////////////////////////////////
    // $text = preg_replace('(\\&.+;)', "", $text);
    // $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    // $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    // $text = preg_replace('~[^-\w]+~', '', $text);
    // $text = trim($text, '-');
    // $text = preg_replace('~-+~', '-', $text);
    // $text = strtolower($text);

    ///////////////////////////////////////////////////////////////////////

//     // Remove unwanted HTML Entities
//     $text = str_replace('&nbsp;', ' ', $text);
//     $text = str_replace('&quot;', '', $text);
//     $text = str_replace('&amp;', 'und', $text);

//     // HTML Entities
//     // $text = htmlentities($text, ENT_COMPAT | ENT_HTML401, 'ISO-8859-1', false);
//     $text = htmlentities($text);
// echo $text." ---> ";
//     $text = preg_replace(array('/&szlig;/','/&(..)lig;/','/&([aouAOU])uml;/','/&(.)[^;]*;/'), array('ss',"$1","$1".'e',"$1"), $text);

//     // replace non letter or digits by -
//     $text = preg_replace('~[^\pL\d]+~u', '-', $text);

//     // transliterate
//     $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

//     // remove unwanted characters
//     $text = preg_replace('~[^-\w]+~', '', $text);

//     // trim
//     $text = trim($text, '-');

//     // remove duplicate -
//     $text = preg_replace('~-+~', '-', $text);

//     // lowercase
//     $text = strtolower($text);

      ///////////////////////////////////////////////////////////////////////
    // // entfernt HTML und PHP Tags aus der URL,falls es solche gibt
    $text = strip_tags($text);

    // Sonderzeichen umwandeln
    $text = str_replace('&nbsp;', ' ', $text);
    $text = str_replace('&quot;', '', $text);
    $text = str_replace('&amp;', 'und', $text);
    $text = str_replace('&', 'und', $text);
    // (ab PHP 5.2.3 wandelt htmlentites bei Bedarf bereits umgewandelte Zeichen nicht nochmals um)
    $text = htmlentities($text);
    // $text = htmlentities($text, ENT_COMPAT | ENT_HTML401, 'ISO-8859-1', false);

    //$text = preg_replace('~&#x([0-9a-f]+);~ei', '', $text);
    $text = preg_replace_callback('~&#x([0-9a-f]+);~', function ($match) { return ''; }, $text);
    //$text = preg_replace('~&#([0-9]+);~e', '', $text);
    $text = preg_replace_callback('~&#([0-9]+);~', function ($match) { return ''; }, $text);
    $text = preg_replace(array('/&szlig;/','/&(..)lig;/','/&([aouAOU])uml;/','/&(.)[^;]*;/'), array('ss',"$1","$1".'e',"$1"), $text);
    $text = str_replace('&', 'und', $text);

    // alles klein, Leerzeichen mit - ersetzen
    $text = strtolower($text);
    $text = trim($text);
    $text = str_replace(' ', '-', $text);
    $text = preg_replace('([^+a-z0-9-_])', '', $text);
    // mehrfache vorkommen von '-' wird ersetzt
    //$text = ereg_replace('-{2,}', '-', $text);
    $text = preg_replace('/-{2,}/', '-', $text);
    //$text = ereg_replace('-$', '', $text);
    $text = preg_replace('/-$/', '', $text);

    ///////////////////////////////////////////////////////////////////////
    // echo $text."\n";

    if (empty($text))
        return 'n-a';

    return $text;
}

/**
 * Utility function to convert Unix timestamp to correct date format including time zone correction
 */
function convertTimestamp($unixTimestamp) {
    $dt = new DateTime();
    $dt->setTimestamp($unixTimestamp);
    $dt->setTimeZone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d\TH:i:s');
}

/**
 * Asks a user a confirmation message and returns true or false based on yes/no.
 */
function confirm($text)
{
    echo "Migrate $text? ";
    $str = trim(strtolower(fgets(STDIN)));

    switch ($str)
    {
        case "yes":
        case "y":
        case "true":
        case "t":
        case "1":
        case "ok":
        case "k":
            return true;
    }

    return false;
}
