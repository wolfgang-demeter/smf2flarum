<?php
require_once 'vendor/autoload.php';
include_once 'settings.php';

// use s9e\TextFormatter\Bundles\Forum as TextFormatter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

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

    // Prepare TextFormatter for custom BBCodes
    $configurator = s9e\TextFormatter\Configurator\Bundles\Forum::getConfigurator();

    // "BBCode 5 Star Rating" extension https://github.com/wolfgang-demeter/flarum-ext-bbcode-5star-rating
    $configurator->BBCodes->addCustom(
        '[FIVESTAR rating={RANGE=0,10}]',
        '<span class="bbcode-fivestar-rating">
            <xsl:choose>
                <xsl:when test="@rating=\'0\'"><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i></xsl:when>
                <xsl:when test="@rating=\'1\'"><i class="fas fa-star-half-alt"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i></xsl:when>
                <xsl:when test="@rating=\'2\'"><i class="fas fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i></xsl:when>
                <xsl:when test="@rating=\'3\'"><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i></xsl:when>
                <xsl:when test="@rating=\'4\'"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i></xsl:when>
                <xsl:when test="@rating=\'5\'"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i><i class="far fa-star"></i><i class="far fa-star"></i></xsl:when>
                <xsl:when test="@rating=\'6\'"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i></xsl:when>
                <xsl:when test="@rating=\'7\'"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i><i class="far fa-star"></i></xsl:when>
                <xsl:when test="@rating=\'8\'"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="far fa-star"></i></xsl:when>
                <xsl:when test="@rating=\'9\'"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i></xsl:when>
                <xsl:when test="@rating=\'10\'"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i></xsl:when>
                <xsl:otherwise><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i></xsl:otherwise>
            </xsl:choose>
        </span>'
    );

    // "Upload by FriendsOfFlarum" extension https://github.com/FriendsOfFlarum/upload/
    $configurator->BBCodes->addCustom(
        '[upl-image-preview url={URL}]',
        ''
    );
    $configurator->BBCodes->addCustom(
        '[upl-file uuid={IDENTIFIER} size={SIMPLETEXT2}]{SIMPLETEXT1}[/upl-file]',
        ''
    );

    $configurator->saveBundle('Smf2FlarumFormatterBundle', '/tmp/Smf2FlarumFormatterBundle.php');
    include '/tmp/Smf2FlarumFormatterBundle.php';

    // Create an array for all posts, to save the determined Flarum posts.number to link to
    $sql = <<<SQL
        SELECT m.ID_TOPIC, m.ID_MSG
        FROM `smf_messages` m
        ORDER BY m.ID_TOPIC, m.posterTime
SQL;
    $posts = $smf->query($sql);
    $posts->setFetchMode(PDO::FETCH_OBJ);

    // determine posts.number
    $postsNumber = array();
    $currentTopic = null;
    while ($post = $posts->fetch()) {
        if ($currentTopic != $post->ID_TOPIC) {
            $currentTopic = $post->ID_TOPIC;
            $number = 0;
        }
        $postsNumber[$post->ID_MSG] = ++$number;
    }
    // print_r($postsNumber);

    // Start the migration process
    if (confirm("Categories"))          migrateCategories($smf, $fla, $api);
    if (confirm("Boards"))              migrateBoards($smf, $fla, $api);
    if (confirm("Users"))               migrateUsers($smf, $fla, $api);
    if (confirm("Posts"))               migratePosts($smf, $fla, $api);
    if (confirm("Last Read Position"))  updateUserLastRead($smf, $fla, $api);
    if (confirm("User Counters"))       updateUserCounters($smf, $fla, $api);
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
    $fla->exec('DELETE FROM `tags`');
    $fla->exec('ALTER TABLE `tags` AUTO_INCREMENT = 1');

    // Insert statement to insert the tags into the table
    $insert = $fla->prepare('INSERT INTO `tags` (name, slug, description, color, position, icon) VALUES (?, ?, ?, ?, ?, ?)');

    // Counters for calculating the percentages
    $total = $smf->query('SELECT COUNT(*) FROM `smf_categories`')->fetchColumn();
    $done = 0;

    // For each category in the SMF backend
    while ($row = $stmt->fetch()) {
        // Display percentage
        $done++;
        echo "Migrating categories: " . $done . "/" . $total . " (" . ((int) ($done / $total * 100)) . "%)\r";

        // ATTENTION: THE FOLLOWING PART IS HIGHLY SPECIFIC TO THE MIGRATED SMF FORUM!!!
        // Dracula-Colors: https://draculatheme.com/contribute
        switch ($row->ID_CAT) {
            case 6:
                $catColor = '#44475a'; // Dracula
                $catDesc = 'Ankündigungen, Hinweise und Tipps rund ums Forum';
                $catOrder = 0;
                // $catIcon = 'fas fa-comment-dots';
                $catIcon = 'fas fa-info-circle';
                break;
            case 4:
                // $catName = 'DVD, Blu-ray & 4K';
                $catColor = '#00a1e0';
                $catDesc = 'News und Informationen zu Allem rund um DVD, Blu-ray und Ultra-HD Blu-ray.';
                $catOrder = 1;
                $catIcon = 'fas fa-compact-disc';
                break;
            case 1:
                // $catName = 'Kino & TV';
                // $catName = 'Kino, Streaming & TV';
                $catColor = '#ff79c6'; // Dracula
                $catDesc = 'News, Berichte und Diskussionenen über Filme & Serien sowie die Leute, die sie machen.';
                $catOrder = 2;
                $catIcon = 'fas fa-ticket-alt';
                break;
            case 9:
                // $catName = 'Reviews';
                $catColor = '#bd93f9'; // Dracula
                $catDesc = 'Besprechungen und Diskussionen über Filme & Serien sowie kurze Reviews.';
                // $catDesc = 'Reviews zu Filmen & Serien.';
                $catOrder = 3;
                $catIcon = 'fas fa-film';
                break;
            case 7:
                // $catName = 'Hardware & Heimkino';
                $catColor = '#ffb86c'; // Dracula
                $catDesc = 'Alles rund um Player, Verstärker, Lautsprecher, Streaming-Anbieter und das eigene Heimkino.';
                $catOrder = 4;
                $catIcon = 'fas fa-video';
                break;
            case 5:
                // $catName = 'Off-topic';
                $catColor = '#6272a4'; // Dracula
                $catDesc = 'Abseits von bewegten Bildern!';
                $catOrder = 5;
                $catIcon = 'fas fa-quote-right';
                break;
            case 8:
                $catColor = '#44475a'; // Dracula
                $catDesc = 'Deals, Schnäppchen und Kaufempfehlungen sowie private Angebote & Gesuche.';
                $catOrder = 6;
                $catIcon = 'fas fa-search-dollar';
                break;
            case 3:
                // $catName = 'Mods & Admin';
                $catColor = '#ff5555'; // Dracula
                $catDesc = 'Nur für Mods & Admins sichtbar!';
                $catOrder = 7;
                $catIcon = 'fas fa-user-lock';
                break;
            default:
                $catColor = '#44475a'; // Dracula
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
    // Make the following slugified boards secondary tags
    $secTags['deutsch'] = array('desc' => 'Medien aus Deutschland (DVD-Regioncode 2 bzw. Blu-ray-Region B) oder deutschsprachige Filme & Serien.', 'color' => '#6272a4', 'icon' => 'fas fa-globe-europe');
    $secTags['rest-der-welt'] = array('desc' => 'Medien, Filme & Serien aus dem Rest der Welt mit Ausnahme von Deutschland & Asien.', 'color' => '#6272a4', 'icon' => 'fas fa-globe-americas');
    $secTags['asien'] = array('desc' => 'Medien, Filme & Serien aus Ausien.', 'color' => '#6272a4', 'icon' => 'fas fa-globe-asia');
    $secTags['animation-und-zeichentrick'] = array('desc' => 'Alles rund um Animes & Zeichentrickfilme.', 'color' => '#6272a4', 'icon' => 'fas fa-dragon');
    $secTags['musik'] = array('desc' => 'Alles rund um Musik.', 'color' => '#6272a4', 'icon' => 'fas fa-music');
    $secTags['games'] = array('desc' => 'Alles rund um Games.', 'color' => '#6272a4', 'icon' => 'fas fa-gamepad');
    $secTagsInserted = array();

    // Create a helper table for all the boards, which stores the SMF board ids with the Flarum equivalents
    $sql = <<<SQL
        CREATE TABLE IF NOT EXISTS `flarum_migrated_boards` (
            smf_board_id INT(10),
            smf_category_id INT(10),
            fla_tag_id INT(10),
            fla_tag_parent_id INT(10)
        );
SQL;
    $smf->exec($sql);
    $smf->exec('TRUNCATE TABLE `flarum_migrated_boards`');

    // Statement to insert the migrated boards id into the helper table
    $sql = "INSERT INTO `flarum_migrated_boards` (smf_board_id, smf_category_id, fla_tag_id, fla_tag_parent_id) VALUES (:smf_board_id, :smf_category_id, :fla_tag_id, :fla_tag_parent_id);";
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
    $insert = $fla->prepare('INSERT INTO `tags` (name, slug, description, color, position, parent_id, icon) VALUES (?, ?, ?, ?, ?, ?, ?)');

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
            $stmt2 = $fla->query('SELECT * FROM `tags` WHERE slug=\'' . slugify($row->cname) . '\'');
            $stmt2->setFetchMode(PDO::FETCH_OBJ);
            $row2 = $stmt2->fetch();

            if (!array_key_exists(slugify($row->bname), $secTags)) {
                // echo "Create PRIMARY tag for:   ".slugify($row2->name.'-'.$row->bname)."\n";
                // Compute the new record to be inserted
                $data = array(
                    preg_replace('(\\&amp;)', '&', $row->bname),
                    slugify($row2->name.'-'.$row->bname),
                    $row->description,
                    $row2->color,
                    $row->boardOrder,
                    $row2->id,
                    '' // $row2->icon --> no icon for second level tags
                );

                // Insert the new record into the database
                $insert->execute($data);

                $flaTagId = $fla->lastInsertId();
                $flaTagParentId = ($row2->id != NULL ? $row2->id : NULL);
            } else {
                // echo "Create SECONDARY tag for: ".slugify($row->bname)."\n";
                // Compute the new record to be inserted
                $data = array(
                    preg_replace('(\\&amp;)', '&', $row->bname),
                    slugify($row->bname),
                    $secTags[slugify($row->bname)]['desc'],
                    $secTags[slugify($row->bname)]['color'],
                    NULL,
                    NULL,
                    $secTags[slugify($row->bname)]['icon']
                );

                // print_r($secTagsInserted);
                if (!array_key_exists(slugify($row->bname), $secTagsInserted)) {
                    // Insert the tag computed into the database
                    $insert->execute($data);

                    $flaTagId = $fla->lastInsertId();
                    $flaTagParentId = ($row2->id != NULL ? $row2->id : NULL);

                    $secTagsInserted[slugify($row->bname)] = $flaTagId;
                } else {
                    // secondary tag alsready inserted; so use it
                    $flaTagId = $secTagsInserted[slugify($row->bname)];
                    $flaTagParentId = ($row2->id != NULL ? $row2->id : NULL);
                }
            }
        } else {
            // All the child boards are secondary tags
            // Compute the new record as secondary tag
            $data = array(
                preg_replace('(\\&amp;)', '&', $row->bname),
                slugify($row->bname),
                $row->description,
                '#6272a4',
                NULL,
                NULL,
                '' // no icon for second level tags
            );

            // Insert the tag computed into the database
            $insert->execute($data);

            $flaTagId = $fla->lastInsertId();
            $flaTagParentId = NULL;
        }

        // Insert the helper record into the helper table
        $insert_mb->execute(array(
            ':smf_board_id' => $row->ID_BOARD,
            ':smf_category_id' => $row->ID_CAT,
            ':fla_tag_id' => $flaTagId,
            ':fla_tag_parent_id' => $flaTagParentId
        ));
    }

    echo "\n";
}

/**
 * Function to migrate the users from the SMF forum to the new Flarum backend. This function is also responsible to translate
 * the member groups from the old forum to the new forum and associate them in the new forum. However the post stats
 * are not migrated. The users will also be migrated without a password, and hence they are required to
 * click on the forgot password link and generate a new password.
 */
function migrateUsers($smf, $fla, $api)
{
    // Clear existing users from the forum except for the admin account created while installing Flarum.
    // Also reset the AUTO_INCREMENT values for the tables.
    $fla->exec('DELETE FROM `users` WHERE id != 1');
    $fla->exec('ALTER TABLE `users` AUTO_INCREMENT = 2');
    $fla->exec('DELETE FROM `group_user` WHERE user_id != 1');
    $fla->exec('ALTER TABLE `group_user` AUTO_INCREMENT = 2');

    // Create a helper table for all the users, which stores the SMF user ids with the Flarum equivalents
    $sql = <<<SQL
        CREATE TABLE IF NOT EXISTS `flarum_migrated_users` (
            smf_id INT(10),
            fla_id INT(10)
        );
SQL;
    $smf->exec($sql);
    $smf->exec('TRUNCATE TABLE `flarum_migrated_users`');

    // Statement to insert the migrated user id into the helper table
    $sql = "INSERT INTO `flarum_migrated_users` (smf_id, fla_id) VALUES (?, ?);";
    $insert_helper = $smf->prepare($sql);

    // The query to select the existing users from the SMF backend
    $sql = <<<SQL
        SELECT ID_MEMBER, memberName, realName, emailAddress, dateRegistered, lastLogin, userTitle, personalText, location, is_activated, ID_GROUP, websiteTitle, websiteUrl, birthdate
        FROM `smf_members` ORDER BY ID_MEMBER ASC
SQL;
    $stmt = $smf->query($sql);
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    // Counters for displaying the progress info
    $total = $smf->query('SELECT COUNT(*) FROM `smf_members`')->fetchColumn();
    $done = 0;

    // The query to insert the new users into the Flarum database
    $sql = <<<SQL
    INSERT INTO `users` (id, username, nickname, email, is_email_confirmed, password, bio, joined_at, last_seen_at, marked_all_as_read_at, social_buttons)
        VALUES (
            ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?
        );
SQL;
    $insert = $fla->prepare($sql);
    $insert2 = $fla->prepare('INSERT INTO `group_user` (user_id, group_id) VALUES (?, ?)');

    // ATTENTION: THE FOLLOWING PART IS HIGHLY SPECIFIC TO THE MIGRATED SMF FORUM!!!
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

    // If you don't want or can't user your original SMF member IDs!
    // $userId = 2;

    // For each user in the SMF database
    while ($row = $stmt->fetch()) {
        // Update and display the progess info
        $done++;
        echo "Migrating users: " . $done . "/" . $total . " (" . ((int) ($done / $total * 100)) . "%)\r";

        // use original SMF Member ID
        // ATTENTION: THIS IS A PROBLEM IF YOU HAVE A SMF USER WITH ID 1 AND YOU KEEP YOUR FLARUM ADMIN USER WITH ID 1!!!
        $userId = $row->ID_MEMBER;

        // add various fields to bio (https://discuss.flarum.org/d/17775-friendsofflarum-user-bio)
        $bio = array();
        if ($row->userTitle !== "") { $bio[] = $row->userTitle; }
        if ($row->location !== "") { $bio[] = $row->location; }
        if ($row->personalText !== "") { $bio[] = $row->personalText; }
        if ($row->birthdate !== "0001-01-01" && substr($row->birthdate, 0, 3) !== "000") { $bio[] = 'geboren am '.substr($row->birthdate, 8, 2).'.'.substr($row->birthdate, 5, 2).'.'.substr($row->birthdate, 0, 4); }

        // Transform the user to the Flarum table
        // websiteUrl is converted to Social Profile (https://discuss.flarum.org/d/18775-friendsofflarum-social-profile)
        $data = array(
            $userId,
            $row->memberName,
            $row->realName,
            $row->emailAddress === "" ? "email-user-id-" . $row->ID_MEMBER . "@example.com" : $row->emailAddress,
            $row->is_activated !== "0" ? 1 : 0,
            replaceBodyStrings(implode(' // ', $bio)),
            convertTimestamp($row->dateRegistered),
            convertTimestamp($row->lastLogin),
            convertTimestamp($row->lastLogin),
            $row->websiteUrl !== "" ? '[{"title":"' . ($row->websiteTitle !== "" ? $row->websiteTitle : $row->websiteUrl) . '","url":"' . $row->websiteUrl . '","icon":"fas fa-globe","favicon":"none"}]' : NULL
        );

        try {
            // Insert the user into the database
            $insert->execute($data);

            // Compute the group record for this user
            $data = array(
                $userId,
                $groupMap[(int) $row->ID_GROUP]
            );

            try {
                // Insert the group record into the database
                $insert2->execute($data);

                // Insert the helper record into the helper table
                $insert_helper->execute(array($row->ID_MEMBER, $userId));
            } catch (Exception $e) {
                echo "Error while updating member group for the following user:\n";
                var_dump($data);
                echo "The message was: " . $e->getMessage() . "\n";
            }
        } catch (Exception $e) {
            echo "Error while porting the following user:\n";
            var_dump($row);
            echo "The message was: " . $e->getMessage() . "\n";
        } finally {
            // we use the original SMF Member IDs!
            // $userId++;
        }

        // Avatar
        $avatar = array();
        $avatar = $smf->query('SELECT * FROM `smf_attachments` WHERE ID_MEMBER = '.$userId)->fetchAll();
        // Buggy Avatar Picture for SMF user with id 739
        if (count($avatar) > 0 && $userId != 739) {
            // echo $userId."\n";
            // var_dump($avatar);
            $avatarUrl = smf_url."index.php?action=dlattach;attach=".$avatar[0]['ID_ATTACH'].";type=avatar";

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
 * Update the users last read position for each discussion
 */
function updateUserLastRead($smf, $fla, $api)
{
    // Clear existing last read positions from the forum except for the admin account created while installing Flarum.
    $fla->exec('DELETE FROM `discussion_user` WHERE user_id != 1');

    $mapPostToNumber = array();
    $posts = $fla->query("SELECT id, discussion_id, number FROM posts ORDER BY id ASC");
    $posts->setFetchMode(PDO::FETCH_OBJ);
    while ($post = $posts->fetch()) {
        $mapPostToNumber[$post->id] = array("discussion_id" => $post->discussion_id, "number" => $post->number);
    }
    // var_dump($mapPostToNumber);

    $lastPostInDiscussion = array();
    $discussions = $fla->query("SELECT id, last_post_id FROM discussions ORDER BY id ASC");
    $discussions->setFetchMode(PDO::FETCH_OBJ);
    while ($discussion = $discussions->fetch()) {
        $lastPostInDiscussion[$discussion->id] = $discussion->last_post_id;
    }
    // var_dump($lastPostInDiscussion);

    $lastReadTopics = $smf->query("SELECT ID_MEMBER, ID_TOPIC, ID_MSG FROM smf_log_topics ORDER BY ID_MEMBER ASC, ID_TOPIC ASC");
    $lastReadTopics->setFetchMode(PDO::FETCH_OBJ);
    while ($lrt = $lastReadTopics->fetch()) {
        echo "\033[2K\r"; // clear line
        echo "Update last read for user with ID " . $lrt->ID_MEMBER . " on discussion with ID " . $lrt->ID_TOPIC . "\r";

        if (array_key_exists($lrt->ID_MSG, $mapPostToNumber)) {
            // If SMF saved a ID_MSG which is actually part of the ID_TOPIC!
            if ($lrt->ID_TOPIC == $mapPostToNumber[$lrt->ID_MSG]['discussion_id']) {
                // echo $lrt->ID_MEMBER . " / " . $mapPostToNumber[$lrt->ID_MSG]['discussion_id'] . " / " . $mapPostToNumber[$lrt->ID_MSG]['number']."\n";
                $fla->query("INSERT INTO discussion_user (`user_id`, `discussion_id`, `last_read_at`, `last_read_post_number`, `subscription`)
                             VALUES(".$lrt->ID_MEMBER.", ".$mapPostToNumber[$lrt->ID_MSG]['discussion_id'].", NULL, ".$mapPostToNumber[$lrt->ID_MSG]['number'].", NULL)");
            }
        } else {
            // Fallback to last post in discussion if SMF saved a random ID_MSG not in ID_TOPIC!
            if (array_key_exists($lrt->ID_TOPIC, $lastPostInDiscussion)) {
                // echo "Fallback to last post in discussion with ID $lrt->ID_TOPIC\n";
                $fla->query("INSERT INTO discussion_user (`user_id`, `discussion_id`, `last_read_at`, `last_read_post_number`, `subscription`)
                             VALUES(".$lrt->ID_MEMBER.", ".$lrt->ID_TOPIC.", NULL, ".$lastPostInDiscussion[$lrt->ID_TOPIC].", NULL)");
            }
        }
    }

    echo "\n";
}

/**
 * Update the discussion and post counters for all users
 */
function updateUserCounters($smf, $fla, $api)
{
    $users = $fla->query("SELECT id FROM users ORDER BY id ASC");
    $users->setFetchMode(PDO::FETCH_OBJ);

    while ($user = $users->fetch()) {
        echo "Update discussion & post counter for user with ID " . $user->id . "\r";

        $discussion_count = $fla->query("SELECT COUNT(*) FROM discussions WHERE user_id = '$user->id'")->fetchColumn();
        $post_count = $fla->query("SELECT COUNT(*) FROM posts WHERE user_id = '$user->id' AND type = 'comment'")->fetchColumn();

        $fla->query("UPDATE users SET discussion_count = '$discussion_count', comment_count = '$post_count' WHERE id = '$user->id'");
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
    $fla->exec('DELETE FROM `posts`');
    $fla->exec('ALTER TABLE `posts` AUTO_INCREMENT = 1');
    $fla->exec('DELETE FROM `discussions`');
    $fla->exec('ALTER TABLE `discussions` AUTO_INCREMENT = 1');
    $fla->exec('DELETE FROM `discussion_tag`');
    $fla->exec('ALTER TABLE `discussion_tag` AUTO_INCREMENT = 1');
    $fla->exec('DELETE FROM `fof_upload_files`');
    $fla->exec('ALTER TABLE `fof_upload_files` AUTO_INCREMENT = 1');

    // SQL query to fetch the topics from the SMF database to migrate
    $sql = <<<SQL
        SELECT
            t.ID_TOPIC, t.ID_MEMBER_STARTED, t.ID_BOARD, m.subject, m.posterTime, m.posterName, t.numReplies, t.locked, t.isSticky,
            u.fla_id
        FROM
            `smf_topics` t
        LEFT JOIN
            `smf_messages` m ON t.ID_FIRST_MSG = m.ID_MSG
        LEFT JOIN
            `flarum_migrated_users` u ON t.ID_MEMBER_STARTED = u.smf_id
        WHERE t.ID_BOARD != 35 -- do not migrate content of board "Papierkorb" (Recycle Bin)
        -- AND t.ID_TOPIC in (228,471,499,1039,1687,1693,9855,15626,17729,26865,27624,27647,27603,27823)
        -- AND t.ID_TOPIC > 27000 OR t.ID_TOPIC in (228,298,471,499,832,1039,1412,1687,6788,11071,1693,6519,9855,14571,14641,14790,14833,14981,15260,15380,15559,15626,17143,17225,17526,17729,18155,20607,21389,26266,26636,26738,26865,26944,26962) -- some test- and edge-cases with current topics
        -- AND t.ID_TOPIC in (298)
        -- AND t.ID_TOPIC in (499,11071)
        -- AND t.ID_TOPIC in (11071)
        -- AND t.ID_TOPIC in (832,1412,14641,14981,21389,26636,27160,27430)
        -- AND t.ID_TOPIC in (27160)
        -- AND t.ID_TOPIC in (27647)
        -- AND t.ID_TOPIC in (27930)
        -- AND t.ID_TOPIC in (228,9855,26266,26944,26962,27930)
        -- AND t.ID_TOPIC in (6519,1013,3574,3586,9985,9986,9987,13281,14269,26069,26325,26403,26448,26477,26636,27160,27647) -- tests to convert internal links
        -- AND t.ID_TOPIC in (6519,13281,14269,26069,26325,26403,26448,26477)
        -- AND t.ID_TOPIC in (3574,3586,26636,27160,27647)
        -- AND t.ID_TOPIC in (14571,14790,14833,15260,15380,15559,17225,17526,18155,20607,27160) -- transfer some special file attachments
        -- AND t.ID_TOPIC >= 27000
        ORDER BY m.posterTime, t.ID_TOPIC
SQL;
    $topics = $smf->query($sql);
    $topics->setFetchMode(PDO::FETCH_OBJ);

    // Counters for displaying the progress info
    $topicsTotal = $smf->query('SELECT COUNT(*) FROM `smf_topics`')->fetchColumn();
    $topicsDone = 0;

    // SQL statement to insert the topic into the Flarum backend
    $sql = <<<SQL
        INSERT INTO `discussions` (
            id, title, comment_count, post_number_index, created_at, user_id, slug, is_locked, is_sticky
        ) VALUES (
            :id, :title, :comment_count, :post_number_index, :created_at, :user_id, :slug, :is_locked, :is_sticky
        );
SQL;
    $insert_topic = $fla->prepare($sql);

    // SQL statement to update the topic into the Flarum backend
    $sql = <<<SQL
        UPDATE `discussions` SET
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
        INSERT INTO `discussion_tag` (`discussion_id`, `tag_id`) VALUES (:discussion_id, :tag_id);
SQL;
    $insert_discussion_tag = $fla->prepare($sql);

    // Mapping Array from SMF Category id to Flarum Tag id
    $map_category_tag = array();
    $flarum_migrated_boards = $smf->query('SELECT * FROM `flarum_migrated_boards`');
    $flarum_migrated_boards->setFetchMode(PDO::FETCH_OBJ);
    while ($migrated_board = $flarum_migrated_boards->fetch()) {
        $map_category_tag[$migrated_board->smf_board_id] = $migrated_board->fla_tag_parent_id;
    }
    // print_r($map_category_tag);

    // Mapping Array from SMF Board id to Flarum Tag id
    $map_board_tag = array();
    $flarum_migrated_boards = $smf->query('SELECT * FROM `flarum_migrated_boards`');
    $flarum_migrated_boards->setFetchMode(PDO::FETCH_OBJ);
    while ($migrated_board = $flarum_migrated_boards->fetch()) {
        $map_board_tag[$migrated_board->smf_board_id] = $migrated_board->fla_tag_id;
    }
    // print_r($map_board_tag);

    // Get all SMF Message Attachments
    $attachments = array();
    $smfAttachments = $smf->query('SELECT * FROM `smf_attachments` WHERE `ID_MSG` != 0 AND `attachmentType` = 0 ORDER BY `ID_MSG` ASC');
    while ($smfAttachment = $smfAttachments->fetch()) {
        if (!array_key_exists($smfAttachment['ID_MSG'], $attachments)) {
            $attachments[$smfAttachment['ID_MSG']] = array();
        }
        array_push($attachments[$smfAttachment['ID_MSG']], $smfAttachment);
    }
    // print_r($attachments);

    // SQL statement to insert the post into the Flarum backend
    $sql = <<<SQL
        INSERT INTO `posts` (
            `id`, `discussion_id`, `type`, `number`, `created_at`, `user_id`, `content`
        ) VALUES (
            :id, :discussion_id, 'comment', :number, :created_at, :user_id, :content
        );
SQL;
    $insert_post = $fla->prepare($sql);

    // Migrate Topics
    while ($topic = $topics->fetch()) {
        // Update and display the progess info
        $topicSlug = slugify($topic->subject);
        $topicsDone++;
        $postsTotal = $topic->numReplies + 1;
        $postsDone = 0;
        echo "\033[2K\r"; // clear line
        echo "Migrating topic ".$topicsDone."/".$topicsTotal." (".((int) ($topicsDone / $topicsTotal * 100))."%) // Topic ID: ".$topic->ID_TOPIC." // number of Posts: ".$postsDone."/".$postsTotal." (0%) // Slug: ".$topicSlug."\r";

        $sql = <<<SQL
            SELECT m.ID_MSG, m.body, m.posterTime, u.fla_id
            FROM `smf_messages` m
            LEFT JOIN `flarum_migrated_users` u ON m.ID_MEMBER = u.smf_id
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
            ':title' => replaceBodyStrings($topic->subject, false),
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

        // Tie discussion to tags
        // former category
        $insert_discussion_tag->execute(array(':discussion_id' => $topic->ID_TOPIC, ':tag_id' => $map_category_tag[$topic->ID_BOARD]));
        // former board
        $insert_discussion_tag->execute(array(':discussion_id' => $topic->ID_TOPIC, ':tag_id' => $map_board_tag[$topic->ID_BOARD]));
        // $insert_discussion_tag->debugDumpParams();

        // Migrate Posts
        while ($post = $posts->fetch()) {
            // Update and display the progess info
            $postsDone++;
            echo "\033[2K\r"; // clear line
            echo "Migrating topic ".$topicsDone."/".$topicsTotal." (".((int) ($topicsDone / $topicsTotal * 100))."%) // Topic ID: ".$topic->ID_TOPIC." // number of Posts: ".$postsDone."/".$postsTotal." (".((int) ($postsDone / $postsTotal * 100))."%) // Slug: ".$topicSlug."\r";

            // Migrate Attachments if any
            $fileAttachmentBBCode = '';
            if (array_key_exists($post->ID_MSG, $attachments)) {
                foreach($attachments[$post->ID_MSG] as $attachment) {
                    // print_r($attachment);
                    // Some attachments are restricted by threads/permissions. We can access them via the file_hash if available - otherwise try the old SMF schema with md5 of filename
                    if ($attachment['file_hash'] != '') {
                        // /attachments/1966_554bc900ba11a55f51cf75a21fe930c21fc99796
                        $attachmentUrl = smf_url."attachments/".$attachment['ID_ATTACH']."_".$attachment['file_hash'];
                    } else {
                        // due to some special charachters the filenames of attachments with the following IDs can not easily re-created with the information at hand
                        $wrongFileNames = array(
                            365 => '365_Presseserver_Paramount_jpg19d0ae52e2d68e80f48f198b63caaea8',
                            417 => '417_Saga_7_jpg68c90cbc6fd78ae8b29da4a974c4d311',
                            419 => '419_Saga_8_jpg638da212f0da696d84c80fd68e5749dd',
                            499 => '499_My_Life_-_Jeder_Augenblick_zAhlt_1_jpg2aa0ae17e3d727b4b9a3fc428e0f04f2',
                            501 => '501_My_Life_-_Jeder_Augenblick_zAhlt_2_jpgd8681991ceee92e8d5c6f3f9a75418ff',
                            530 => '530_NotenschlAssel_jpgcdb6b41cbcda6abf1b50bebd54b8e8ef',
                            532 => '532_NotenschlAssel_jpgcdb6b41cbcda6abf1b50bebd54b8e8ef',
                        );

                        if (array_key_exists($attachment['ID_ATTACH'], $wrongFileNames)) {
                            $attachmentUrl = smf_url."attachments/".$wrongFileNames[$attachment['ID_ATTACH']];
                        } else {
                            // /index.php?action=dlattach;topic=14641.0;attach=206;image
                            // $attachmentUrl = smf_url."index.php?action=dlattach;topic=".$topic->ID_TOPIC.".0;attach=".$attachment['ID_ATTACH'].";image";

                            // /attachments/828_Foto7_jpg47460f64d0fd5700d62fa6894d2f8ad4
                            // md5 of $attachment['filename']
                            $attachmentUrl = smf_url."attachments/".$attachment['ID_ATTACH']."_".str_replace(array('.', ' '), '_', $attachment['filename']).md5(str_replace(' ', '_', $attachment['filename']));
                        }
                    }
                    echo "\033[2K\r"; // clear line
                    echo "Migrating attachment ID ".$attachment['ID_ATTACH']." of User ID ".$post->fla_id." for Topic ID: ".$topic->ID_TOPIC." // URL: ".$attachmentUrl."\r";

                    // Content-Disposition: form-data; name="files[]"; filename="some-image-filename.jpg"
                    if (file_put_contents('/tmp/attachmentmigration', fopen($attachmentUrl, 'r'))) {
                        try {
                            $response = $api->request(
                                'POST',
                                'fof/upload',
                                [
                                    'multipart' => [
                                        [
                                            'name' => 'files[]',
                                            'filename' => $attachment['filename'],
                                            'contents' => fopen('/tmp/attachmentmigration', 'r'),
                                        ]
                                    ]
                                ]
                            );
                            $fofUploadResponse = json_decode($response->getBody(), true);
                            // print_r($fofUploadResponse);

                            // update actor_id for file-attachment in database; fallback to ID 1 (fladmin)
                            $fla->query("UPDATE fof_upload_files SET actor_id = ".(is_int($post->fla_id) ? $post->fla_id : 1)." WHERE id = ".$fofUploadResponse['data'][0]['id']);

                            // prepare BBCode to attach at end of Post
                            $fileAttachmentBBCode = $fileAttachmentBBCode."\n\n".$fofUploadResponse['data'][0]['attributes']['bbcode'];
                        } catch (ClientException $e) {
                            echo "FAILED migrating attachment ID: ".$attachment['ID_ATTACH']." with filename '".$attachment['filename']."' of User ID: ".$post->fla_id." for Topic ID: ".$topic->ID_TOPIC." // URL: ".$attachmentUrl."\n";
                        }
                    }
                }
            }

            // save Post
            $data = array(
                ':id' => $post->ID_MSG,
                ':discussion_id' => $topic->ID_TOPIC,
                ':number' => $post_counter++,
                ':created_at' => convertTimestamp($post->posterTime),
                ':user_id' => $post->fla_id,
                ':content' => Smf2FlarumFormatterBundle::parse(replaceBodyStrings($post->body, true, true).$fileAttachmentBBCode)
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
    }

    echo "\n";
}

/**
 * Utility function to replace some characters prior to storing in Flarum.
 */
function replaceBodyStrings($str, $replaceSmileys = true, $convertInternalLinks = false)
{
    // Line-Breaks
    $str = preg_replace("/\<br\>/", "\n", $str);
    $str = preg_replace("/\<br\s*\/\>/", "\n", $str);

    // HTML Entities
    $str = preg_replace("/&#039;/", "'", $str);
    $str = preg_replace("/&#8364;/", "€", $str);
    $str = html_entity_decode($str, ENT_COMPAT | ENT_HTML5, 'UTF-8');

    // BBCode
    // https://www.phpliveregex.com/#tab-preg-replace
    // $str = preg_replace("/\[size=([0-9]*)(.*)\](.*)\[\/size\]/", "[size=$1]$3[/size]", $str);
    // $str = preg_replace("/\[size=([0-9]*)([a-zA-Z]*)\]/", "[size=$1]", $str);
    // [size] 1. resize from old 11px base font-size to new 16px and use 75% of it to be less dominant
    // [size] 2. recalculate pt to px
    $str = preg_replace_callback("/\[size=([0-9]*)([a-zA-Z]*)\]/", function ($match) { return "[size=".($match[1] == 'px' ? round(intval($match[1])*16/11*0.75) : round((intval($match[1])*16/11)*96/72))."]"; }, $str);
    $str = preg_replace("/\[size=([0-9]*)([a-zA-Z]*)\]/", "[size=$1]", $str);
    $str = preg_replace("/\[quote\][\s\t\r\n]*\[\/quote\]/", "", $str);
    $str = preg_replace("/\[me=(.*)\](.*)\[\/me\]/U", "\n[i][color=grey]* $1 $2[/color][/i]", $str);
    $str = preg_replace("/\[youtube\]https?:\/\/(.*)\[\/youtube\]/", "https://$1", $str);

    // ATTENTION: THE FOLLOWING PART IS HIGHLY SPECIFIC TO THE MIGRATED SMF FORUM!!!
    // Emojis and Smileys (as configured in SMF)
    if ($replaceSmileys) {
        $str = preg_replace("/(\s|^)8\)(\s|$)/", " 😎", $str); // 8) only with leading and trailing whitespace; otherwise text like "Drunken Master (1978)" will have an Emoji!
        $str = preg_replace("/8-\)/", "😎", $str);
        $str = preg_replace("/:\(/", "🙁", $str);
        $str = preg_replace("/:\)/", "😀", $str);
        $str = preg_replace("/:-\(/", "🙁", $str);
        $str = preg_replace("/:-\)/", "😀", $str);
        $str = preg_replace("/:-\?/", "🤨", $str);
        $str = preg_replace("/:-D/", "😁", $str);
        $str = preg_replace("/:-o/", "😆", $str);
        $str = preg_replace("/:-P/", "😛", $str);
        $str = preg_replace("/:-x/", "😖", $str);
        $str = preg_replace("/:-\|/", "😐", $str);
        $str = preg_replace("/:0narr:/", "[fivestar rating=0]", $str);
        $str = preg_replace("/:1narr:/", "[fivestar rating=2]", $str);
        $str = preg_replace("/:2narr:/", "[fivestar rating=4]", $str);
        $str = preg_replace("/:3narr:/", "[fivestar rating=6]", $str);
        $str = preg_replace("/:4narr:/", "[fivestar rating=8]", $str);
        $str = preg_replace("/:5narr:/", "[fivestar rating=10]", $str);
        $str = preg_replace("/:\?/", "🤨", $str);
        $str = preg_replace("/:\?\?\?:/", "🤨", $str);
        $str = preg_replace("/:aufgeregt:/", "🤩", $str); // maybe
        $str = preg_replace("/:brav:/", "🥳", $str); // maybe
        $str = preg_replace("/:breakdance:/", "💃", $str);
        $str = preg_replace("/:ciao:/", "🏃‍♂️", $str);
        $str = preg_replace("/:cool:/", "😎", $str);
        $str = preg_replace("/:cry:/", "😥", $str);
        $str = preg_replace("/:D/", "😁", $str);
        $str = preg_replace("/:daumen:/", "👍", $str);
        $str = preg_replace("/:daumenhoch:/", "👍", $str);
        $str = preg_replace("/:deal:/", "📜", $str); // maybe
        $str = preg_replace("/:doof:/", "🙈", $str); // maybe
        $str = preg_replace("/:eek:/", "😆", $str);
        $str = preg_replace("/:evil:/", "😠", $str);
        $str = preg_replace("/:evilnarr:/", "😠", $str);
        $str = preg_replace("/:grin:/", "😁", $str);
        $str = preg_replace("/:grrr:/", "😤", $str);
        $str = preg_replace("/:headbang:/", "😂", $str);
        // $str = preg_replace("/:ignore:/", "🥱", $str); // maybe
        $str = preg_replace("/:ignore:/", "🤷🏻", $str); // maybe
        $str = preg_replace("/:king:/", "👑", $str);
        $str = preg_replace("/:kotz:/", "🤮", $str);
        $str = preg_replace("/:kratz:/", "🤔", $str);
        $str = preg_replace("/:lol:/", "😁", $str);
        $str = preg_replace("/:love:/", "😍", $str);
        $str = preg_replace("/:mad:/", "😖", $str);
        $str = preg_replace("/:megaschock:/", "😱", $str);
        $str = preg_replace("/:motz:/", "🤬", $str);
        $str = preg_replace("/:mrgreen:/", "😂", $str); // maybe
        $str = preg_replace("/:narr0:/", "[fivestar rating=0]", $str);
        $str = preg_replace("/:narr1:/", "[fivestar rating=1]", $str);
        $str = preg_replace("/:narr2:/", "[fivestar rating=2]", $str);
        $str = preg_replace("/:narr3:/", "[fivestar rating=3]", $str);
        $str = preg_replace("/:narr4:/", "[fivestar rating=4]", $str);
        $str = preg_replace("/:narr5:/", "[fivestar rating=5]", $str);
        $str = preg_replace("/:narr6:/", "[fivestar rating=6]", $str);
        $str = preg_replace("/:narr7:/", "[fivestar rating=7]", $str);
        $str = preg_replace("/:narr8:/", "[fivestar rating=8]", $str);
        $str = preg_replace("/:narr9:/", "[fivestar rating=9]", $str);
        $str = preg_replace("/:narr10:/", "[fivestar rating=10]", $str);
        $str = preg_replace("/:narrentip:/", "[b][i]NarrenTipp[/i][/b]🏆🏅", $str);
        $str = preg_replace("/:neutral:/", "😐", $str);
        $str = preg_replace("/:o(\s|$)/", "😆 ", $str); // only with trailing whitespace or eol
        $str = preg_replace("/:oops:/", "😊", $str);
        $str = preg_replace("/:P(\s|$)/", "😛 ", $str); // only with trailing whitespace or eol
        $str = preg_replace("/:prost:/", "🍻", $str);
        $str = preg_replace("/:razz:/", "😛", $str);
        // $str = preg_replace("/:respekt:/", "🤩", $str); // maybe
        $str = preg_replace("/:respekt:/", "🖖", $str); // maybe
        $str = preg_replace("/:roll:/", "🙄", $str);
        $str = preg_replace("/:rotfl:/", "🤣", $str);
        $str = preg_replace("/:sad:/", "🙁", $str);
        $str = preg_replace("/:schlau:/", "☝️", $str); // maybe
        $str = preg_replace("/:shock:/", "😲", $str);
        $str = preg_replace("/:smile:/", "😀", $str);
        $str = preg_replace("/:spam:/", "[b][i]Spam[/i][/b]😣", $str);
        $str = preg_replace("/:stirnklatsch:/", "🤦‍♂️", $str);
        $str = preg_replace("/:tip:/", "[b][i]Tipp[/i][/b]🏆🏅", $str);
        $str = preg_replace("/:twisted:/", "👹", $str);
        $str = preg_replace("/:uglyhammer:/", "🤣🤣🤣", $str);
        $str = preg_replace("/:vader:/", "🦹", $str); // maybe
        $str = preg_replace("/:verneig:/", "🙇", $str);
        $str = preg_replace("/:wall:/", "🤦‍♂️", $str);
        $str = preg_replace("/:wink:/", "😉", $str);
        $str = preg_replace("/:x/", "😖", $str);
        $str = preg_replace("/:zzzz:/", "😴", $str);
        $str = preg_replace("/:\|/", "😐", $str);
        $str = preg_replace("/;\)/", "😉", $str);
        $str = preg_replace("/;-\)/", "😉", $str);
    }

    if ($convertInternalLinks) {
        $str = convertInternalLinks($str);
    }

    return $str;
}

/**
 * Utility function to compute slugs for topics, categories and boards.
 */
function slugify($str)
{
    // remove all tags
    $str = strip_tags($str);

    // convert html entities
    $str = str_replace('&nbsp;', ' ', $str);
    $str = str_replace('&quot;', '', $str);
    $str = str_replace('&amp;', 'und', $str);
    $str = str_replace('&', 'und', $str);
    $str = htmlentities($str);

    // convert unicode characters
    $str = preg_replace_callback('~&#x([0-9a-f]+);~', function ($match) { return ''; }, $str);
    $str = preg_replace_callback('~&#([0-9]+);~', function ($match) { return ''; }, $str);

    // convert special german characters (ßäöü)
    $str = preg_replace(array('/&szlig;/','/&(..)lig;/','/&([aouAOU])uml;/','/&(.)[^;]*;/'), array('ss',"$1","$1".'e',"$1"), $str);
    $str = str_replace('&', 'und', $str);

    // lowercase and spaces as -
    $str = strtolower($str);
    $str = trim($str);
    $str = str_replace(' ', '-', $str);
    $str = preg_replace('([^+a-z0-9-_])', '', $str);
    // remove multiple '-'
    $str = preg_replace('/-{2,}/', '-', $str);
    $str = preg_replace('/-$/', '', $str);

    // if nothing is left, use 'n-a'
    if (empty($str)) {
        return 'n-a';
    }

    return $str;
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
 * Convert old internal SMF URLs to new Flarum URLs
 */
function convertInternalLinks($str)
{
    // prepare smf_url for regular expressions
    $smfUrl = str_replace('https', 'https?', preg_quote(smf_url, "/"));

    // https://example.com/community/index.php?topic=27160.0
    // https://example.com/community/index.php?topic=27999.msg343450#msg343450
    $regexp = '/'.$smfUrl.'index.php\?(topic=(\d+))+(\.(msg|d*)(\d+))*+(\#msg\d+)*/is';
    if (preg_match_all($regexp, $str, $matches)) {
        $str = preg_replace_callback(
            $regexp,
            function ($matches) {
                global $postsNumber;
                // print_r($matches);
                if (array_key_exists('5', $matches) && $matches[5] != 0) {
                    return fla_url."d/".$matches[2]."/".$postsNumber[$matches[5]];
                } else {
                    return fla_url."d/".$matches[2];
                }
            },
            $str
        );
    }

    // http://example.com/community/index.php/topic,19844.0.html
    // http://example.com/community/index.php/topic,19844.msg343450.html
    $regexp = '/'.$smfUrl.'index.php\/(topic,(\d+))+(\.(msg|d*)(\d+))*+((\#msg\d+)|.html)*/is';
    if (preg_match_all($regexp, $str, $matches)) {
        $str = preg_replace_callback(
            $regexp,
            function ($matches) {
                global $postsNumber;
                // print_r($matches);
                if (array_key_exists('5', $matches) && $matches[5] != 0) {
                    return fla_url."d/".$matches[2]."/".$postsNumber[$matches[5]];
                } else {
                    return fla_url."d/".$matches[2];
                }
            },
            $str
        );
    }

    // Replace very old phpBB Links
    // https://example.com/forum/viewtopic.php?t=3574
    $smfUrl = str_replace('community', 'forum', $smfUrl);
    $regexp = '/'.$smfUrl.'viewtopic.php\?(t=(\d+)(.*))*/is';
    if (preg_match_all($regexp, $str, $matches)) {
        // print_r($matches);
        $str = preg_replace($regexp, fla_url."d/$2", $str);
    }

    return $str;
}

/**
 * Asks a user a confirmation message and returns true or false based on yes/no.
 */
function confirm($prompt)
{
    echo "Migrate $prompt? (y/n) ";
    $str = trim(strtolower(fgets(STDIN)));

    switch ($str) {
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
