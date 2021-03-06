<!DOCTYPE HTML>  
<html>
<head>
<style>
  #feedback { font-size: 1.4em; }
  #selectable .ui-selecting { background: #FECA40; }
  #selectable .ui-selected { background: #F39814; color: white; }
  #selectable { list-style-type: none; margin: 0; padding: 0; width: 60%; }
  #selectable li { margin: 3px; padding: 0.4em; font-size: 1.0em; height: 18px; display: inline-block; border: 1px solid #32373c; border-radius: 3px; padding-bottom: 5px; cursor: pointer;}
  </style>
  <script src="https://code.jquery.com/jquery-1.12.4.js"></script>
  <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
  <script>
  $( function() {
      $(".ui-widget-content").click( function() {
        $(this).toggleClass("ui-selected");
        $.ajax({
            type: "POST",
            url: "admin-ajax.php",
            data: {
              'action': "update_post_types_to_sync",
              'clicked': $(this).attr("name")
              }
          });
      })
  });
  </script>
</head>
<body>  

<?php
  define("FIRESTORE_URL2", "https://firestore.googleapis.com/v1/projects/".get_option('firebase_projectid')."/databases/(default)/documents/");
  define("FIRESTORE_PROJECT_URL2", "projects/".get_option('firebase_projectid')."/databases/(default)/documents/");

  $arrayTypes = array();
  $disabled=array("nav_menu_item",'customize_changeset','revision','custom_css','oembed_cache','user_request','wp_block');
  $types = get_post_types( [], 'objects' );
  $selectedOptions=get_option('post_types_array');
    foreach ( $types as $type ) {
      if ( isset( $type->name ) &&  !in_array($type->name, $disabled) ) {
        //$currItem=array("name"=>$type->name,"selected"=>in_array($type->name, $selectedOptions));
        $currItem=array("name"=>$type->name,"selected"=>!empty($selectedOptions)?in_array($type->name, $selectedOptions):false);
        array_push($arrayTypes, $currItem);
    }
  }
  
  $actionStatus = 0;
  $actionCategoriesSyncStatus = 0;
  $post_types = get_option('post_types_array');
  
  /* Echo variable
   * Description: Uses <pre> and print_r to display a variable in formated fashion
   */
  function echo_log( $what ){
    echo '<pre>'.print_r( $what, true ).'</pre>';
  }

  function debug_funcc($data,$file="debug"){
    $myfile = fopen(__DIR__ .'/debug/'.$file.'.txt', 'w');
    //fwrite($myfile, json_encode( (array)$data ));
    fwrite($myfile, json_encode( $data ));
    fclose($myfile);
  }

  /**
   * Returning the post category by it's ID
   * @param {Integer} post_id - post id 
   */
  function getPostCategory2($post_id){
    global $wpdb;
  
    $query = "SELECT * FROM (SELECT * FROM(SELECT meta_id,post_id FROM {$wpdb->prefix}postmeta WHERE post_id=".$post_id.") a
              INNER JOIN {$wpdb->prefix}term_relationships ON {$wpdb->prefix}term_relationships.object_id = a.post_id) b
              INNER JOIN {$wpdb->prefix}term_taxonomy ON {$wpdb->prefix}term_taxonomy.term_taxonomy_id = b.term_taxonomy_id GROUP BY {$wpdb->prefix}term_taxonomy.taxonomy";
  
    $category = $wpdb->get_results($query);
  
    return $category;
  }

  function wpDataToFirestoreData2($data){
    $postData = array(  
      'fields' => array(),
    );
  
    foreach ($data as $key => $value){  
      $postData['fields'][$key]=array("stringValue"=>$value.""); 
     }

    return $postData;
  }
  
  function sendDataToFirestore2($postData, $shouldIDoAConversion=true, $type, $id, $action_type, $isCategory){
  
    //$postMeta=get_post_meta($data['ID']);
    if($shouldIDoAConversion){
      $type=$postData['post_type'];
      $id=$postData['ID'];
      $postData=wpDataToFirestoreData2($postData);
    }

    if(!$isCategory){
      $postCategory = getPostCategory2($postData["fields"]["ID"]['stringValue']);
      
      if(!empty($postCategory)){
        if(count($postCategory) > 1){
          foreach($postCategory as $key => $obj){
            //debug_func($obj, $obj->meta_id);
            //$postData['fields']['collection']=array("referenceValue"=>"projects/mytestexample-d5aaa/databases/(default)/documents/".$obj->taxonomy."/".$obj->term_id);
            $postData['fields']['collection_'.$obj->taxonomy]=array("referenceValue"=>FIRESTORE_PROJECT_URL2.$obj->taxonomy."/".$obj->term_id);
          }
        }else{
          //collection category reference
          //$postData['fields']['collection']=array("referenceValue"=>"projects/mytestexample-d5aaa/databases/(default)/documents/".$postCategory[0]->taxonomy."/".$postCategory[0]->term_id);
          $postData['fields']['collection_'.$postCategory[0]->taxonomy]=array("referenceValue"=>FIRESTORE_PROJECT_URL2.$postCategory[0]->taxonomy."/".$postCategory[0]->term_id);
        }
      }
      /*if(!empty($postCategory)){
        //collection category reference
        $postData['fields']['collection']=array("referenceValue"=>"projects/mytestexample-d5aaa/databases/(default)/documents/".$postCategory[0]->taxonomy."/".$postCategory[0]->term_id);
      }*/
    }  
    //if publish post
    if($action_type == "publish"){
      $url = FIRESTORE_URL2.$type."?documentId=".$id;
    
      wp_remote_post($url, array(
        'headers'     => array('Content-Type' => 'application/json; charset=utf-8'),
        'body'        => json_encode($postData),
        'method'      => 'POST',
        'data_format' => 'body',
      ));
    //if update post
    }else if($action_type == "update"){
      $url = FIRESTORE_URL2.$type."/".$id;
    
      wp_remote_post($url, array(
        'headers'     => array('Content-Type' => 'application/json; charset=utf-8'),
        'body'        => json_encode($postData),
        'method'      => 'PATCH',
        'data_format' => 'body',
      ));
    }
  }

  //Synchronize all categories from database and check for their meta additional data
  function saveCategories2(){
    global $wpdb;
    
    //$query = "SELECT * FROM {$wpdb->prefix}terms INNER JOIN {$wpdb->prefix}term_taxonomy ON {$wpdb->prefix}terms.term_id={$wpdb->prefix}term_taxonomy.term_id";
    $query = "SELECT {$wpdb->prefix}terms.term_id, {$wpdb->prefix}terms.name, {$wpdb->prefix}term_taxonomy.taxonomy FROM {$wpdb->prefix}terms INNER JOIN {$wpdb->prefix}term_taxonomy ON {$wpdb->prefix}terms.term_id={$wpdb->prefix}term_taxonomy.term_id";
    $categories = $wpdb->get_results($query);

    //New JOIN
    $query = "SELECT * FROM (SELECT categories_meta.term_id, categories_meta.name, categories_meta.taxonomy, categories_meta.meta_key, categories_meta.meta_value, {$wpdb->prefix}posts.guid FROM 
              (SELECT categories.term_id, categories.name, categories.taxonomy, {$wpdb->prefix}termmeta.meta_key, {$wpdb->prefix}termmeta.meta_value FROM 
              (SELECT {$wpdb->prefix}terms.term_id, {$wpdb->prefix}terms.name, {$wpdb->prefix}term_taxonomy.taxonomy FROM {$wpdb->prefix}terms 
              INNER JOIN {$wpdb->prefix}term_taxonomy ON {$wpdb->prefix}terms.term_id={$wpdb->prefix}term_taxonomy.term_id) categories
              LEFT JOIN {$wpdb->prefix}termmeta ON categories.term_id={$wpdb->prefix}termmeta.term_id) categories_meta
              LEFT JOIN {$wpdb->prefix}posts ON categories_meta.meta_value={$wpdb->prefix}posts.ID
              ORDER BY term_id) a WHERE meta_value IS NOT NULL";
    
    $meta_categories = $wpdb->get_results($query);
  
    foreach ($meta_categories as $key => $element) {
      foreach($categories as $new_key => $new_element){
        if($element->term_id == $new_element->term_id){
          $meta_key = $element->meta_key;
          if($element->guid != null){
            $categories[$new_key]->$meta_key = $element->guid;
          }else{
            $categories[$new_key]->$meta_key = $element->meta_value;
          }
         
        }
      }
    }
    
    foreach ($categories as $key => $element) {
      sendDataToFirestore2(wpDataToFirestoreData2($element),false,$element->taxonomy,$element->term_id,"publish", true);
    }
  }

  /**
   * Returning all posts by post type that is selected
   * @param {String} post type that is selected
   */
  function getAllPostsByPostType($post_type){
    global $wpdb;

    $query = "SELECT * FROM {$wpdb->prefix}posts WHERE {$wpdb->prefix}posts.post_type='".$post_type."'";
    return $wpdb->get_results($query);
  }

  //Handle Cat sync
  if(isset($_GET['dofullcatsync'])){
    saveCategories2();
    $actionCategoriesSyncStatus = 1;
  }

  //Handle full post sync
  if(isset($_GET['dofullpostsync'])){
    if(is_array($post_types)){
      foreach ($post_types as $key => $type) {

        $posts = getAllPostsByPostType($type);
        
        foreach ($posts as $post_key => $post){
          //if is ID of author return his display name
          if(intval($post->post_author)){
            $author = get_userdata($post->post_author);
            $post->post_author = $author->display_name;
          }

          sendDataToFirestore2((array) $post, true, $post->post_type, $post->ID, "publish", false);
        }
      }
    //check if postTypes is string -> only one postTypes
    }else{
      $posts = getAllPostsByPostType($post_types);
      foreach ($posts as $post_key => $post){
        //if is ID of author return his display name
        if(intval($post->post_author)){
          $author = get_userdata($post->post_author);
          $post->post_author = $author->display_name;
        }
        
        sendDataToFirestore2((array) $post, true, $post->post_type, $post->ID, "publish", false);
      }
    }
    $actionPostSyncStatus=1;
  }

  //HANDLE POST REQUEST
  if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if(!(empty($_POST["apikey"]) || empty($_POST["projectid"]) || empty($_POST["appid"]))){
        
      update_option('firebase_apikey', $_POST["apikey"]);
      update_option('firebase_projectid', $_POST["projectid"]);
      update_option('firebase_appid', $_POST["appid"]);
      update_option('firebase_authdomain', $_POST["projectid"] . ".firebaseapp.com");
      update_option('firebase_databaseurl', "https://" . $_POST["projectid"] . ".firebaseio.com");
    }else{
      add_option('firebase_apikey', $_POST["apikey"]);
      add_option('firebase_projectid', $_POST["projectid"]);
      add_option('firebase_appid', $_POST["appid"]);
      add_option('firebase_authdomain', $_POST["projectid"] . ".firebaseapp.com");
      add_option('firebase_databaseurl', "https://" . $_POST["projectid"] . ".firebaseio.com");
    }
    $actionStatus=1;
    //header("Refresh:0");
    //header("Location: ".$_SERVER['PHP_SELF']);
    echo("<meta http-equiv='refresh' content='1'>");
  }
?>
<div class="wrap">
  <h1>UniExpo Plugin</h1>
  <?php if($actionStatus==1){ ?>
    <div class="notice notice-success settings-error is-dismissible alert-saved">
    <p>Project Settings Saved!</p>
  </div>
  <?php } ?>
  <?php if($actionCategoriesSyncStatus==1){ ?>
    <div class="notice notice-success settings-error is-dismissible alert-saved">
    <p>Categories Synchronized successfully!</p>
  </div>
  <?php } ?>
  <?php if($actionPostSyncStatus==1){ ?>
    <div class="notice notice-success settings-error is-dismissible alert-saved">
    <p>Post Types Synchronized successfully!</p>
  </div>
  <?php } ?>
  <br/>
  <h2>Firebase Project Settings</h2>
  <hr/>
  <form method="post" action="admin.php?page=uniexpo-plugin" novalidate="novalidate">
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">
          <label for="blogname">apiKey</label>
        </th>
        <td>
          <input name="apikey" type="text" id="apikey" value="<?php echo get_option('firebase_apikey');?>" class="regular-text" />
        </td>
      </tr>
      <tr>
        <th scope="row">
          <label for="blogdescription">projectId</label>
        </th>
        <td>
        <input name="projectid" type="text" id="projectid" value="<?php echo get_option('firebase_projectid');?>" class="regular-text" />
        </td>
      </tr>
      <tr>
        <th scope="row">
          <label for="blogdescription">appId</label>
        </th>
        <td>
        <input name="appid" type="text" id="appid" value="<?php echo get_option('firebase_appid');?>" class="regular-text" />
        </td>
      </tr>
    </table> 
    <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes"  /></p>
    <br/>
    <?php if(get_option('firebase_projectid')): ?>
    <h2>Sync Settings</h2>
    <hr/>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">
          <label for="blogname">Post types to sync</label>
        </th>
        <td>
          <ol id="selectable">
            <!--<li class="ui-widget-content">Item 1</li>-->
            <?php if(is_array($arrayTypes)): ?>
              <?php foreach($arrayTypes as $post_type): ?>
                <li id="ui-widget" class="ui-widget-content <?php  echo $post_type['selected']?"ui-selected":""; ?>" name=<?php echo $post_type['name'] ?>><?php echo $post_type['name'] ?></li>
              <?php endforeach; ?>
            <?php else : ?>
              <!--<li class="ui-widget-content"> execute sth here</li>-->
            <?php endif; ?>
          </ol>
          <p class="description" id="tagline-description">Select one of the post types below to start sync the data.</p>
        </td>
      </tr>
    </table>
    </form>
      <h2>Initial Full sync</h2>
      <hr/>
    <table class="form-table" role="presentation">
      <tr>
        <td>
          <p class="description" id="tagline-description">Note: synchronization can take some time. Please be patient.</p>
          <br/>
          <a href="admin.php?page=uniexpo-plugin&dofullcatsync=true" class="button button-primary" value="Categories">Categories</a>
            &nbsp;&nbsp;  
            <a href="admin.php?page=uniexpo-plugin&dofullpostsync=true" class="button button-primary" value="Post Types">Post Types</a>
        </td>
      </tr>
    </table>
    <br/>
    <?php endif; ?>
</div>
</body>
</html>