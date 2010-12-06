<?php
/*
Plugin Name: Database Browser
Plugin URI: http://www.stillbreathing.co.uk/wordpress/database-browser/
Description: Easily browse the data in your database, and download in CSV, XML and JSON format
Author: Chris Taylor
Version: 1.0
Author URI: http://www.stillbreathing.co.uk/
*/

if( !class_exists( 'DatabaseBrowser' ) ) {

	class DatabaseBrowser {
	
		// set some properties
		var $version = "1.0";
		var $tables = array();
		var $table = null;
		var $columns = array();
		var $rowcount = array();
		var $rows = array();
		var $error = null;
		var $query = null;
		var $formURL;
		
		// initialise the plugin
		function DatabaseBrowser() {
		
			if ( isset( $_POST["table"] ) && $_POST["table"] != "" ) {
				header( "Location: tools.php?page=databasebrowser&table=" . $_POST["table"] );
			}
		
			require_once( WP_PLUGIN_DIR . "/database-browser/pagination.class.php" );
			$this->formURL = remove_query_arg( "p" );
		
		}
		
		// =====================================================================================================================
		// Initialisation
		
		// when the WordPress admin is initialised
		function on_admin_init() {
			// get the requested table name
			$this->table = @$_GET["table"];
			
			// if exporting, do the export
			if ( $this->table != "" && @$_GET["export"] != "" ) {
				$this->export();
			}
			
			// load textdomain
			load_plugin_textdomain( 'databasebrowser', false, 'databasebrowser/languages' );
			
			// enqueue CSS
			$this->enqueue_admin_css();
			
			// enqueue JS
			$this->enqueue_admin_js();
		}
		
		// when the WordPress admin are is initialised
		function on_admin_menu() {
			add_submenu_page( 'tools.php', __( "Database browser", "databasebrowser" ), __( "Database browser", "databasebrowser" ), 'export', 'databasebrowser', array( $this, "adminPage" ) );
		}
		
		// enqueue the CSS for the admin area
		function enqueue_admin_css() {
			wp_register_style( 'databasebrowser', WP_PLUGIN_URL . "/database-browser/database-browser.css" );
			wp_enqueue_style( 'databasebrowser' );
		}
		
		// enqueue the JS for the admin area
		function enqueue_admin_js() {
			wp_enqueue_script( $handle = 'databasebrowser-js', $src = plugins_url('database-browser.js', __FILE__), $deps = array(), $ver = $this->version , true );
		}
		
		// =====================================================================================================================
		// Admin page
		
		function adminPage() {
			echo '
			<div class="wrap" id="databasebrowser">
				<h1>' . __( "Database browser", "databasebrowser" ) . '</h1>
			';
			
			// if a table has been chosen, load the data
			if ( $this->table != "" ) {
			
				$limit = 100;
				$paginator = new Paginator( $limit );

				// load the table
				$this->loadTable( $paginator->findStart(), $limit );
			
				echo '
				<h2>' . sprintf( __( "Table: %s", "databasebrowser" ), $this->table ) . '</h2>
				';
				
				if ( $this->error != null && $this->error != "" ) {
					echo '
					<div id="message" class="updated">
						<p><strong>' . __( "Database error", "databasebrowser" ) . '</strong></p>
						<p>' .$this->error . '</p>
					</div>
					';
				}
				
				echo '
				<form action="' . $this->formURL . '" method="post">
				<p><label id="wherelabel" for="where">' . __( "Where:", "databasebrowser" ) . '</label>
					<textarea name="where" cols="30" rows="6" style="width:100%;height:4em" class="hider" id="where">' . @stripslashes( $_POST["where"] ) . '</textarea>
				</p>
				<p><label id="orderbylabel" for="orderby">' . __( "Order by:", "databasebrowser" ) . '</label>
					<textarea name="orderby" cols="30" rows="6" style="width:100%;height:4em" class="hider" id="orderby">' . @stripslashes( $_POST["orderby"] ) . '</textarea>
				</p>
				<p><button type="submit" class="button">' . __( "Run where and order by clauses", "databasebrowser" ) . '</button></p>
				</form>
				';
			
				// no data found
				if ( $this->rowcount == 0 ) {
				
					echo '
					<div id="message" class="updated">
						<p><strong>' . __( "No data was found in this table.", "databasebrowser" ) . '</strong></p>
					</div>';
				
				} else {
				
					$paginator->findPages( $this->rowcount );
				
					// write the export links
					$this->exportLinks();
				
					// write the data table with pagination
					echo $paginator->links;
					echo '<div class="tablewrapper">';
					echo $this->toHTML( "widefat" );
					echo '</div>';
					echo $paginator->links;
					
					// write the export links
					$this->exportLinks();
				
				}
			
			}
			
			// load tables
			$this->loadTables();
			
			// show tables list
			echo '
			<form action="tools.php?page=databasebrowser" method="post">
			<p>
				<label for="tablelist">' . __( "Select table", "databasebrowser" ) . '</label>
				<select name="table" id="tablelist">
				';
				foreach( $this->tables as $table ) {
					echo '
					<option value="' . $table . '">' . $table . '</option>
					';
				}
				echo '
				</select>
				<input type="submit" class="button-primary" value="' . __( "Select table", "databasebrowser" ) . '" />
			</p>
			</form>
			';
			
			if ( $this->query != null && $this->query != "" ) {
			
				echo '
				<h4 id="queryheader">' . __( "Query performed:", "databasebrowser" ) . '</h4>
				<p class="hider" id="query"><textarea name="query" cols="30" rows="6" style="width:100%;height:4em">' . $this->query . '</textarea></p>
				';
			
			}
			
			echo '
			</div>
			';
		}
		
		// =====================================================================================================================
		// Database manipulation
		
		function loadTables() {
			global $wpdb;
			$sql = $wpdb->prepare( "SHOW TABLES;" );
			$results = $wpdb->get_results( $sql, ARRAY_N );
			$tables = array();
			foreach( $results as $result ) {
				$tables[] = $result[0];
			}
			$this->tables = $tables;
		}
		
		function loadTable( $start = 0, $limit = 100 ) {
			if ( $this->table != "" ) {
				$this->loadTableColumns();
				$this->loadRows( $start, $limit );
			}
		}
		
		function loadTableColumns() {
			global $wpdb;
			$sql = "SHOW COLUMNS FROM " . $wpdb->escape( $this->table ) . ";";
			$this->columns = $wpdb->get_results( $sql );
		}
		
		function loadRows( $start = 0, $limit = 100 ) {
			global $wpdb;
			$sql = "SELECT SQL_CALC_FOUND_ROWS ";
			foreach( $this->columns as $column ) {
				$sql .= $column->Field . ", ";
			}
			$sql = trim( trim( $sql ), "," );
			$sql .= " FROM " . $wpdb->escape( $this->table );
			// using a where
			if ( !empty( $_POST["where"] ) ) {
				$sql .= " WHERE " . stripslashes( $_POST["where"] );
			}
			// using an order by
			if ( !empty( $_POST["orderby"] ) ) {
				$sql .= " ORDER BY " . stripslashes( $_POST["orderby"] );
			}
			// using a limit
			if ( $limit != null ) {
				$sql .= $wpdb->prepare( " LIMIT %d, %d;", $start, $limit );
			}
			$sql .= ";";
			$this->query = $sql;
			$this->rows = $wpdb->get_results( $sql, ARRAY_A );
			$this->error = mysql_error( $wpdb->dbh );
			$this->rowcount = $wpdb->get_var( "SELECT FOUND_ROWS();" );
		}
		
		// =====================================================================================================================
		// Export
		
		function exportLinks() {
			
			echo '
			<div id="exportlinks">
				<ul>
					<li><a href="tools.php?page=databasebrowser&amp;table=' . $this->table . '&amp;export=XML" class="button">XML</a></li>
					<li><a href="tools.php?page=databasebrowser&amp;table=' . $this->table . '&amp;export=HTML" class="button">HTML</a></li>
					<li><a href="tools.php?page=databasebrowser&amp;table=' . $this->table . '&amp;export=CSV" class="button">CSV</a></li>
					<li><a href="tools.php?page=databasebrowser&amp;table=' . $this->table . '&amp;export=JSON" class="button">JSON</a></li>
				</ul>
			</div>
			';
			
		}
		
		function export() {
			
			// load the table with all rows
			$this->loadTable( null, null );
			
			$format = strtolower( $_GET["export"] );
			
			switch ( $format ) {
				case "xml":
					$this->forceDownload( "xml" );
					echo $this->toXML();
					die();
					break;
				case "html":
					$this->forceDownload( "html" );
					echo $this->toHTML();
					die();
					break;
				case "csv":
					$this->forceDownload( "csv" );
					echo $this->toCSV();
					die();
					break;
				case "json":
					$this->forceDownload( "json" );
					echo $this->toJSON();
					die();
					break;
			}
		}
		
		function forceDownload( $format ) {
			header( "Cache-Control: public" );
		    header( "Content-Description: WordPress Database Browser Table Export" );
		    header( "Content-Disposition: attachment; filename=" . DB_NAME . "." . $this->table . "." . $format );
		    header( "Content-Type: application/octet-stream" );
		}
		
		function toJson() {
			$output = '{
	"database": "' . DB_NAME . '",
	"table": "' . $this->table . '",
	"columns":
	{';
			foreach( $this->columns as $column ) {
				$output .= '
		"column":
		{
			"name": "' . str_replace( '"', '\\"', $column->Field ) . '",
			"type": "' . str_replace( '"', '\\"', $column->Type ) . '",
			"null": "' . str_replace( '"', '\\"', $column->Null ) . '",
			"key": "' . str_replace( '"', '\\"', $column->Key ) . '",
			"default": "' . str_replace( '"', '\\"', $column->Default ) . '",
			"extra": "' . str_replace( '"', '\\"', $column->Extra ) . '"
		},
		';
			}
			$output = trim( trim( $output ), "," );
			$output .= '
	},
	"rows":
	{';
			foreach( $this->rows as $row ) {
				$line = '
		"row":
		{
		';
				$col = '';
				foreach( $this->columns as $column ) {
					$col .= '
			"' . $column->Field . '": "' . $row[ $column->Field ] . '",';
				}
				$line .= trim( trim( $col ), "," );
				$line .= '
		},';
				$output .= $line;
			}
			$output = trim( trim( $output ), "," );
			$output .= '
	}
}';
			return $output;
		}
		
		function toCSV() {
			$output = "";
			foreach( $this->columns as $column ) {
				$output .= $column->Field . ",";
			}
			$output = trim( $output, "," );
			$output .= "\r\n";
			foreach( $this->rows as $row ) {
				$line = "";
				foreach( $this->columns as $column ) {
					$line .= '"' . str_replace( '"', '""', $row[ $column->Field ] ) . '",';
				}
				$line = trim( $line, "," );
				$line .= "\r\n";
				$output .= $line;
			}
			$output = trim( $output );
			return $output;
		}
		
		function toHTML( $class="" ) {
			$output = '
<table class="' . $class . '">
	<thead>
		<tr>';
			foreach( $this->columns as $column ) {
				$output .= "
			<th>" . $column->Field . "</th>";
			}
			$output .= "
		</tr>
	</thead>
	<tbody>";
			foreach( $this->rows as $row ) {
				$line = "
		<tr>";
				foreach( $this->columns as $column ) {
					$line .= '
			<td>' . $row[ $column->Field ] . '</td>';
				}
				$line .= "
		</tr>";
				$output .= $line;
			}
			$output .= "
	</tbody>
</table>
";
			return $output;
		}
		
		function toXML() {
			$output = '<?xml version="1.0" encoding="ISO-8859-1" ?> 
<database name="' . DB_NAME . '">
	<table name="' . $this->table . '">
		<columns>';
			foreach( $this->columns as $column ) {
				$output .= '
			<column name="' . $column->Field . '" type="' . $column->Type . '" null="' . $column->Null . '" key="' . $column->Key . '" default="' . $column->Default . '" extra="' . $column->Extra . '" />';
			}
			$output .= '
		</columns>
		<rows>';
			foreach( $this->rows as $row ) {
				$line = '
			<row>';
				foreach( $this->columns as $column ) {
					$line .= '
				<' . $column->Field . '>' . $row[ $column->Field ] . '</' . $column->Field . '>';
				}
				$line .= '
			</row>';
				$output .= $line;
			}
			$output .= '
		</rows>
	</table>
</database>
';
			return $output;
		}
	
	}

}

// initialise DatabaseBrowser
if( class_exists( 'DatabaseBrowser' ) ) {
	$databasebrowser = new DatabaseBrowser();
	add_action( 'admin_menu', array( &$databasebrowser, 'on_admin_menu' ) );
	add_action( 'admin_init', array( &$databasebrowser, 'on_admin_init' ) );
}
?>