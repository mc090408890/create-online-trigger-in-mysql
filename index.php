<?php
include_once('dbcon.php');

ini_set('display_errors', '0');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch the list of all tables
    $stmt = $pdo->prepare("SHOW TABLES");
    $stmt->execute();
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
	$randomString=generateRandomString(2);
    
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle form submission
        $selectedTable = $_POST['table'];
        $selectedColumns = $_POST['columns'];
        $triggerType = str_replace(' ', '_', $_POST['trigger_type']).'_'.strtolower($randomString); // Replace spaces with underscores for trigger name
        $triggerTypeC = $_POST['trigger_type']; // Actual trigger type (INSERT, UPDATE, DELETE)
        
        // Prepare JSON structure for old and new values
        $json_old_values = [];
        $json_new_values = [];

        // Handle old and new values based on trigger type
        foreach ($selectedColumns as $column) {
            if (strpos($triggerType, 'INSERT') !== false) {
                // INSERT triggers: old value is null
                $json_old_values[] = "'\"$column\":\"NULL\"'";
                $json_new_values[] = "'\"$column\":\"', NEW.$column, '\"'";
            } elseif (strpos($triggerType, 'DELETE') !== false) {
                // DELETE triggers: new value is null
                $json_old_values[] = "'\"$column\":\"', OLD.$column, '\"'";
                $json_new_values[] = "'\"$column\":\"NULL\"'";
            } else {
                // UPDATE triggers: handle both old and new values
                $json_old_values[] = "'\"$column\":\"', OLD.$column, '\"'";
                $json_new_values[] = "'\"$column\":\"', NEW.$column, '\"'";
            }
        }
			
			
			// Prepare and execute the query
		$sql = "SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_STATEMENT, ACTION_TIMING 
				FROM information_schema.TRIGGERS 
				WHERE EVENT_OBJECT_TABLE = :tableName AND TRIGGER_SCHEMA = :databaseName";

		$stmt = $pdo->prepare($sql);
		$stmt->execute(['tableName' => $selectedTable, 'databaseName' => $dbname]);
		// Fetch the triggers
		$triggers_result = $stmt->fetchAll();

        // Join the JSON structure
        $old_value_json = "CONCAT('{', " . implode(", ',', ", $json_old_values) . ", '}')";
        $new_value_json = "CONCAT('{', " . implode(", ',', ", $json_new_values) . ", '}')";

        // Get the primary key of the selected table (used as idFk)
        $query = "SHOW INDEXES FROM $selectedTable WHERE Key_name = 'PRIMARY'";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $table_idFk = 'OLD.' . $result['Column_name'];
		

        // Define the trigger SQL for direct MySQL execution with the IF condition
        $triggerSQL = "CREATE TRIGGER {$triggerType}_{$selectedTable} 
                        {$triggerTypeC} ON {$selectedTable} 
                        FOR EACH ROW 
                        BEGIN 
                            IF ($old_value_json != $new_value_json) THEN
                                INSERT INTO audit_log (table_idFk, table_name, old_value, new_value, changed_at)
                                VALUES ($table_idFk, '$selectedTable', $old_value_json, $new_value_json, NOW());
                            END IF;
                        END;";

        // Define the trigger SQL for phpMyAdmin with DELIMITER $$ syntax and IF condition
        $phpMyAdminTriggerSQL = "
        DELIMITER $$
        CREATE TRIGGER {$triggerType}_{$selectedTable}
        {$triggerTypeC} ON {$selectedTable}
        FOR EACH ROW 
        BEGIN
            IF ($old_value_json != $new_value_json) THEN
                INSERT INTO audit_log (table_idFk, table_name, old_value, new_value, changed_at)
                VALUES ($table_idFk, '$selectedTable', $old_value_json, $new_value_json, NOW());
            END IF;
        END$$
        DELIMITER ;";

        // Define the CREATE TABLE SQL for the audit_log table
        $createAuditTableSQL = "
        CREATE TABLE IF NOT EXISTS audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(255),
            table_idFk INT,
            old_value JSON,
            new_value JSON,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );";
		
		
		
	

				
        // Output all SQL sections in text areas with copy buttons
        $triggers.= "
        <div class='container'>
            <h4>Direct MySQL Trigger:</h4>
            <textarea id='mysqlTrigger' class='form-control' rows='6'>{$triggerSQL}</textarea>
            <button class='btn btn-primary mt-2' onclick='copyToClipboard(\"mysqlTrigger\")'>Copy MySQL Trigger</button>
			
            <h4 class='mt-4'>phpMyAdmin-Compatible Trigger:</h4>
            <textarea id='phpMyAdminTrigger' class='form-control' rows='6'>{$phpMyAdminTriggerSQL}</textarea>
            <button class='btn btn-primary mt-2' onclick='copyToClipboard(\"phpMyAdminTrigger\")'>Copy phpMyAdmin Trigger</button>

            <h4 class='mt-4'>Create Table for audit_log:</h4>
            <textarea id='createTableSQL' class='form-control' rows='4'>{$createAuditTableSQL}</textarea>
            <button class='btn btn-primary mt-2' onclick='copyToClipboard(\"createTableSQL\")'>Copy Create Table SQL</button>
        </div>";
		
			
			
			
			if (isset($triggers_result) && count($triggers_result)>0) {
			$triggers_history="";
			$triggers_history.= "<table border='0'  cellpadding='10' cellspacing='0'>";
			$triggers_history.= "<thead>
					<tr>
						<th> No #</th>
						<th> Existing Trigger Name</th>
						<th>Event</th>
						<th>Statment</th>
					
						<th>Timing</th>
					</tr>
				  </thead>";
			$triggers_history.= "<tbody>";
			 $i=0;
			foreach ($triggers_result as $trigger) {
				$i++;
				
				$triggers_history.= "<tr>";
				$triggers_history.= "<td>" . $i . "</td>";
				$triggers_history.= "<td>" . htmlspecialchars($trigger['TRIGGER_NAME']) . "</td>";
				$triggers_history.= "<td>" . htmlspecialchars($trigger['EVENT_MANIPULATION']) . "</td>";
				$triggers_history.= "<td>" . htmlspecialchars($trigger['ACTION_STATEMENT']) . "</td>";
				
				$triggers_history.= "<td>" . htmlspecialchars($trigger['ACTION_TIMING']) . "</td>";
				$triggers_history.= "</tr>";
			}
			
			$triggers_history.= "</tbody>";
			$triggers_history.= "</table>";
		} 

		$triggers_history.= "";
		
		
        $triggers.= "
		  <footer class='text-muted'>
            <div style='position: fixed; bottom: 10px; right: 10px;'>
				
                <small>Created by: Mian Anjum<br>Email: miananjum20@gmail.com<br>
				Git Hub Link: <a href='https://github.com/mc090408890/create-online-trigger-in-mysql'>Git Hub</a></small>
            </div>
        </footer>
		
        <script>
            function copyToClipboard(elementId) {
                var copyText = document.getElementById(elementId);
                copyText.select();
                copyText.setSelectionRange(0, 99999); // For mobile devices
                document.execCommand('copy');
                alert('Copied the text from ' + elementId);
            }
        </script>
       ";
		echo $triggers;
		echo $triggers_history;
        exit();
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}

function generateRandomString($length = 4) {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);

    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;

}


?>


<!DOCTYPE html>
<html>
<head>
    <title>Create MySQL Trigger</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <div class="container mt-5">
        <div class="card">
            <div class="card-header">
                <h3>Create MySQL Trigger</h3>
            </div>
            <div class="card-body">
                <form method="POST" id="trigger-form">
                    <div class="mb-3">
                        <label for="table" class="form-label">Select Table:</label>
                        <select id="table" name="table" class="form-select" onchange="fetchColumns(this.value)">
                            <option value="">Select a table</option>
                            <?php foreach ($tables as $table) { ?>
                                <option value="<?= $table ?>"><?= $table ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="mb-3" id="columns-container">
                        <label for="columns" class="form-label">Select Columns:</label>
                        <select id="columns" name="columns[]" class="form-select" multiple>
                            <!-- Column options will be loaded here dynamically -->
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="trigger_type" class="form-label">Trigger Type:</label>
                        <select id="trigger_type" name="trigger_type" class="form-select">
                            <option value="BEFORE INSERT">Before Insert</option>
                            <option value="AFTER INSERT">After Insert</option>
                            <option value="BEFORE UPDATE">Before Update</option>
                            <option value="AFTER UPDATE">After Update</option>
                            <option value="BEFORE DELETE">Before Delete</option>
                            <option value="AFTER DELETE">After Delete</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">Create Trigger</button>
                </form>

                <div id="result" class="mt-3"></div>
            </div>
        </div>
    </div>

    <script>
        // jQuery AJAX function to fetch columns for the selected table
        function fetchColumns(table) {
            if (table) {
                $.ajax({
                    url: 'fetch_columns.php',
                    method: 'POST',
                    data: {table: table},
                    success: function(response) {
                        $('#columns').html(response);
                    }
                });
            } else {
                $('#columns').html('');
            }
        }

        // jQuery to handle the form submission
        $(document).ready(function() {
            $('#trigger-form').on('submit', function(e) {
                e.preventDefault();
                $.ajax({
                    url: '', // Current page URL
                    method: 'POST',
                    data: $(this).serialize(),
                    success: function(response) {
                        $('#result').html('<div class="alert alert-success">' + response + '</div>');
                    }
                });
            });
        });
    </script>

    <!-- Bootstrap JS -->
</body>
</html>