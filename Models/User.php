<?php
// Simple User model for interacting with users table
class User
{
    protected $conn;

    public function __construct($dbConnection)
    {
        $this->conn = $dbConnection;
    }

    public function existsByUsernameOrEmail($username, $email)
    {
        $sql = 'SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('ss', $username, $email);
        $stmt->execute();
        $stmt->store_result();
        $exists = ($stmt->num_rows > 0);
        $stmt->close();
        return $exists;
    }

    public function create(array $data)
    {
        // expected keys: username, passwordHash, name, email, phonenumber, user_role
        $sql = 'INSERT INTO users (username, password, name, email, phonenumber, user_role) VALUES (?, ?, ?, ?, ?, ?)';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('ssssss', $data['username'], $data['passwordHash'], $data['name'], $data['email'], $data['phonenumber'], $data['user_role']);
        $ok = $stmt->execute();
        if ($ok) {
            $insertId = $stmt->insert_id;
            $stmt->close();
            return $insertId;
        }
        $stmt->close();
        return false;
    }

    public function findByUsernameOrEmail($identity)
    {
        $sql = 'SELECT user_id, username, password, name, email, phonenumber, user_role FROM users WHERE username = ? OR email = ? LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('ss', $identity, $identity);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();
        $stmt->close();
        return $user ?: null;
    }

    public function findByEmail($email)
    {
        $sql = 'SELECT user_id, username, password, name, email, phonenumber, user_role FROM users WHERE email = ? LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();
        $stmt->close();

        return $user ?: null;
    }

    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $sql = 'UPDATE users SET password = ? WHERE user_id = ? LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $passwordHash, $userId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}
