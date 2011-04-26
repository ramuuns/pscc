<!DOCTYPE html>
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <title>PSCC usage example</title>
    </head>
    <body>
		<?php if ( isset($_POST['sql'])) {  ?>
			<pre><?php
				include 'pscc.php';
				$table = PSCC::getTableInfoFromCreateStatement($_POST['sql']);
				//var_dump($table);
				echo htmlspecialchars(implode(";\n",PSCC::getTranslatedTableDef($table, $_POST['db_type'])),ENT_COMPAT,'utf-8');
			?></pre>
		<?php } ?>

		<form method="post" action="">
			<label>Target DB Type
			<select name="db_type">
				<option value="mysql">MySQL</option>
				<option value="pgsql">PostgreSQL</option>
			</select></label><br/>
			<label> Create statement (assumed valid)<br/>
				<textarea name="sql" rows="20" cols="60"><?php echo isset($_POST['sql'])?htmlspecialchars($_POST['sql'],ENT_COMPAT,'utf-8'):''; ?></textarea>
			</label><br/>
			<button type="submit">Convert</button>
		</form>
        
    </body>
</html>
