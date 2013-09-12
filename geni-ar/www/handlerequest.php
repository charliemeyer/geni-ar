<?php
//----------------------------------------------------------------------
// Copyright (c) 2012 Raytheon BBN Technologies
//
// Permission is hereby granted, free of charge, to any person obtaining
// a copy of this software and/or hardware specification (the "Work") to
// deal in the Work without restriction, including without limitation the
// rights to use, copy, modify, merge, publish, distribute, sublicense,
// and/or sell copies of the Work, and to permit persons to whom the Work
// is furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be
// included in all copies or substantial portions of the Work.
//
// THE WORK IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
// OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
// MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
// NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
// HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
// WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE WORK OR THE USE OR OTHER DEALINGS
// IN THE WORK.
//----------------------------------------------------------------------
require_once('db_utils.php');
require_once('ldap_utils.php');
require_once('geni_syslog.php');
require_once('response_format.php');
require_once('ssha.php');
include_once('/etc/geni-ar/settings.php');

$errors = array();

/* ---------------- */
/* Transform inputs */
/* ---------------- */
//
// We'll get 'username', but the column is 'username_requested'
if (array_key_exists('username', $_REQUEST) && $_REQUEST['username']) {
  $_REQUEST['username_requested'] = $_REQUEST['username'];
  unset($_REQUEST['username']);
} else {
  $errors[] = "No username specified.";
  // Do this to avoid an unnecessary message to the user
  // about 'username_requested' not specified.
  $_REQUEST['username_requested'] = ' ';
}

$p1 = null;
$p2 = null;
$email = null;
$ldapconn = ldap_setup();
if ($ldapconn === -1) {
  print "Failed to connect to ldap server";
  exit();
}
//sanity checks - does username exist, does email exist
//is username properly formed (1-8 characters, lower case letters and numbers only)

//check if there is already an account for this email
if (array_key_exists('email', $_REQUEST) && $_REQUEST['email']) {
  $email = $_REQUEST['email'];
  if (ldap_check_email($ldapconn,$email)) {
    $errors[] = "An account for this email address already exists.";
  }
}
$uid = $_REQUEST['username_requested'];

if (ldap_check_account($ldapconn,$uid)) {
  $errors[] = "This username is already in use.";
} else {
  if (strlen($uid) > 8) {
    $errors[] = "username cannot be longer than 8 characters.";
  }
  if (!preg_match('/^[a-z0-9]{1,8}$/', $uid)) {
    $errors[] = "username must consist of lowercase letters and numbers only.";
  }
  
}
if (array_key_exists('password1', $_REQUEST) && $_REQUEST['password1']) {
  $p1 = $_REQUEST['password1'];
} else {
  $errors[] = "No password specified.";
}
if (array_key_exists('password2', $_REQUEST) && $_REQUEST['password2']) {
  $p2 = $_REQUEST['password2'];
} else {
  $errors[] = "No confirm password specified.";
}
if ($p1 === $p2) {
  // Create the password_hash
  $pw_hash = SSHA::newHash($p1);
  $_REQUEST['password_hash'] = $pw_hash;
} else {
  $errors[] = "Passwords do not match.";
}



/* ----------------- */
/* Extract variables */
/* ----------------- */
// var => db_type
$required_vars = array('first_name' => 'text',
                       'last_name' => 'text',
                       'email' => 'text',
                       'username_requested' => 'text',
                       'phone' => 'text',
                       'password_hash' => 'text',
                       'organization' => 'text',
                       'title' => 'text',
                       'reason' => 'text');

$optional_vars = array('url' => 'text');

// Write database row
// Build the insert statement
$query_vars = array();
$query_values = array();

/* open a database connection */
$conn = db_conn();

foreach ($required_vars as $name => $db_type) {
  if (array_key_exists($name, $_REQUEST) && $_REQUEST[$name]) {
    $value = $_REQUEST[$name];
    $query_vars[] = $name;
    $query_values[] = $conn->quote($value, $db_type);
  } else {
    $pretty_name = str_replace("_", " ", $name);
    $errors[] = "No $pretty_name specified.";
  }
}

foreach ($optional_vars as $name => $db_type) {
  if (array_key_exists($name, $_REQUEST)) {
    $value = $_REQUEST[$name];
    $query_vars[] = $name;
    $query_values[] = $conn->quote($value, $db_type);
  }
}

if ($errors) {
?>

<!DOCTYPE html>
<html>
<head>
<title>GENI: Request an account</title>
<link type="text/css" href="kmtool.css" rel="Stylesheet"/>
</head>
<body>
  <div id="header">
    <a href="http://www.geni.net" target="_blank">
      <img src="geni.png" width="88" height="75" alt="GENI"/>
    </a>
    <h2>Problems with request</h2>
    <p>Please fix the following problems with your request:</p>
    <ul>
<?php foreach ($errors as $error) { ?>
    <li><?php echo $error; ?></li>
<?php } ?>
    </ul>
  <p>Please use the browser back button to try again.</p>
  <p>If you have any questions, please send email to <a href="mailto:help@geni.net">help@geni.net</a>.
  </div> <!-- header -->
  <hr/>
  <div id="footer">
    <small><i>
        <a href="http://www.geni.net/">GENI</a>
        is sponsored by the
        <a href="http://www.nsf.gov/">
          <img src="https://www.nsf.gov/images/logos/nsf1.gif"
               alt="NSF Logo" height="25" width="25">
          National Science Foundation
        </a>
    </i></small>
  </div> <!-- footer -->
</body>
</html>
<?php
  exit();
}
?>

<?php

$sql = 'INSERT INTO idp_account_request (';
$sql .= implode(',', $query_vars);
$sql .= ') VALUES (';
$sql .= implode (',', $query_values);
$sql .= ')';


$result = db_execute_statement($sql, 'insert idp account request');
$server_host = $_SERVER['SERVER_NAME'];
if ($result[RESPONSE_ARGUMENT::CODE] != RESPONSE_ERROR::NONE) {
  // An error occurred. First, log the query and result for debugging
  geni_syslog("IDP request query", $sql);
  geni_syslog("IDP request result", print_r($result, true));
  // Next send an email about the error
  mail($idp_approval_email,
    "IdP Account Request Failure $server_host",
   'An error occurred on IdP account request. See /var/log/user.log for details.');
  // Finally pop up an error page
} else {
  // Success
  $subject = "New IdP Account Request on $server_host";
  $body = 'A new IdP account request has been submitted on host ';
  $body .= "$server_host.\n\n";
  $email_vars = array('first_name', 'last_name', 'email',
          'organization', 'title', 'reason');
  foreach ($email_vars as $var) {
    $val = $_REQUEST[$var];
    $body .= "$var: $val\n";
  }
  $body .= "\nSee table idp_account_request for complete details.\n";
  mail($idp_approval_email, $subject, $body);

  //Now email the requester
  $subject = "GENI Identity Provider Account Request Received";
  $body = 'Thank you for requesting an Identity Provider account with GENI.';
  $body .= "You will be contacted if there are any questions about your request and notified when the account has been created.";
  mail($email, $subject, $body);
  
}

?>
<!DOCTYPE html>
<html>
<head>
<title>GENI: Request an account</title>
<link type="text/css" href="kmtool.css" rel="Stylesheet"/>
</head>
<body>
  <div id="header">
    <a href="http://www.geni.net" target="_blank">
      <img src="geni.png" width="88" height="75" alt="GENI"/>
    </a>
<?php if ($result[RESPONSE_ARGUMENT::CODE] != RESPONSE_ERROR::NONE) { ?>
    <h1>ERROR</h1>
    <h2>Account request failed</h2>
    <p>
    We're sorry, your account request failed. An email has been sent to the operators and they will be in touch with you shortly.
    </p>
<?php } else { ?>
    <h2>Account request received.</h2>
    <p>
    Congratulations, your account request has been received. We will be in touch with you about the status of your request.
    </p>
<?php } ?>
  <p>If you have any questions, please send email to <a href="mailto:help@geni.net">help@geni.net</a>.
  </div> <!-- header -->
  <hr/>
  <div id="footer">
    <small><i>
        <a href="http://www.geni.net/">GENI</a>
        is sponsored by the
        <a href="http://www.nsf.gov/">
          <img src="https://www.nsf.gov/images/logos/nsf1.gif"
               alt="NSF Logo" height="25" width="25">
          National Science Foundation
        </a>
    </i></small>
  </div> <!-- footer -->
</body>
</html>