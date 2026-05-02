<?php
session_start();

// 1. SESSION CHECK: If a user is already logged in, redirect them.
// If they are a logistics engineer, kick them out immediately.
if (isset($_SESSION['user'])) {
    $role = $_SESSION['user']['Role'];

    if ($role === 'logistics engineer') {
        session_destroy();
        header("Location: /~g1154085/index.php?error=unauthorized");
        exit;
    } elseif ($role === 'driver') {
        header("Location: /~g1154085/driver/home.php");
        exit;
    } else {
        header("Location: /~g1154085/warehouse/overview.php");
        exit;
    }
}

$error = '';

// 2. LOGIN ATTEMPT: Handle the form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        include 'includes/db.php';
        
        $u = mysqli_real_escape_string($conn, $username);
        $p = mysqli_real_escape_string($conn, $password);
        
        $result = mysqli_query($conn, "
            SELECT EmployeeID, FirstName, LastName, Role 
            FROM Employee 
            WHERE Username='$u' AND PasswordHash='$p' AND EmploymentStatus='active'
        ");
        
        $user = mysqli_fetch_assoc($result);

        if ($user) {
            // Check if the user is a logistics engineer BEFORE starting the session
            if ($user['Role'] === 'logistics engineer') {
                $error = 'Access Denied: Logistics engineers are not permitted to use this portal.';
            } else {
                $_SESSION['user'] = $user;
                
                if ($user['Role'] === 'driver') {
                    header("Location: /~g1154085/driver/home.php");
                } else {
                    header("Location: /~g1154085/warehouse/overview.php");
                }
                exit;
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

// 3. UI DATA: Team member information
$members = array(
    array('name' => 'Karlin To',         'photo' => 'karlin.jpg'),
    array('name' => 'Morgan Terry',      'photo' => 'morgan.jpg'),
    array('name' => 'Alexander Thews',   'photo' => 'alex.jpg'),
    array('name' => 'Pierce Tully',      'photo' => 'pierce.jpg'),
    array('name' => 'Keshav Goel',       'photo' => 'keshav.jpg'),
    array('name' => 'Sneha Chakraborty', 'photo' => 'sneha.jpg'),
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PharmaCool ERP &mdash; Login</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/~g1154085/css/style.css">
</head>
<body class="login-page">
<div class="login-wrapper">
  <h1 class="login-title">PharmaCool ERP</h1>
  <p class="login-subtitle">Cold-Chain Pharmaceutical Logistics Management</p>

  <div class="team-grid">
    <?php foreach ($members as $m): ?>
    <div class="team-member">
      <img src="/~g1154085/images/<?php echo htmlspecialchars($m['photo']); ?>" 
           alt="<?php echo htmlspecialchars($m['name']); ?>" class="member-photo">
      <p class="member-name"><?php echo htmlspecialchars($m['name']); ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="login-box">
    <h2>Welcome back</h2>
    <p>Sign in to your account</p>
    
    <?php if ($error): ?>
      <div class="alert alert-error" style="color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
        <?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="/~g1154085/index.php">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" 
               value="<?php echo htmlspecialchars(isset($_POST['username']) ? $_POST['username'] : ''); ?>" 
               placeholder="Enter username" autocomplete="username" required>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" 
               placeholder="Enter password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="login-btn">Login</button>
    </form>
  </div>
</div>
</body>
</html>