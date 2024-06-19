<body>
    <h1>MongoDB_PDO - 一個適用於 MongoDB 的 PDO 擴展</h1>
    <p>MongoDB_PDO 是一個 PHP 的 PDO 擴展類,允許開發者使用熟悉的 PDO 介面來操作 MongoDB 資料庫。它提供了一種將 SQL 語句轉換為 MongoDB 操作的方法,使開發者可以更輕鬆地遷移到
        MongoDB。</p>
    <h2>功能特性</h2>
    <ul>
        <li>支援基本的 CRUD 操作(Create、Read、Update、Delete)</li>
        <li>支援 WHERE 子句,包括 AND、OR、IN、LIKE 等條件</li>
        <li>支援 ORDER BY 和 LIMIT 子句</li>
        <li>支援 JOIN 操作(LEFT JOIN)</li>
        <li>支援 GROUP BY 和聚合函數(COUNT、SUM)</li>
        <li>支援命名參數綁定</li>
    </ul>

 <h2>使用範例</h2>

  <h3>建立連接</h3>
    <pre><code>$dsn = 'mongodb://localhost:27017';
$dbname = 'mydatabase';
$mongo = new MongoDB_PDO($dsn, $dbname);</code></pre>
    <h3>查詢資料</h3>
    <pre><code>$stmt = $mongo->prepare('SELECT * FROM users WHERE age > :age');
$stmt->execute(['age' => 18]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);</code></pre>
    <h3>插入資料</h3>
    <pre><code>$stmt = $mongo->prepare('INSERT INTO users (name, email) VALUES (:name, :email)');
$stmt->execute(['name' => 'John Doe', 'email' => 'john@example.com']);</code></pre>
    <h3>更新資料</h3>
    <pre><code>$stmt = $mongo->prepare('UPDATE users SET age = :age WHERE name = :name');
$stmt->execute(['age' => 20, 'name' => 'John Doe']);</code></pre>
    <h3>刪除資料</h3>
    <pre><code>$stmt = $mongo->prepare('DELETE FROM users WHERE age < :age');
$stmt->execute(['age' => 18]);</code></pre>
    <h2>安裝</h2>
    <ol>
        <li>確保已經安裝了 MongoDB PHP 驅動程式。</li>
        <li>將 <code>MongoDB_PDO.php</code> 檔案包含到您的專案中。</li>
    </ol>

 <h2>貢獻</h2>
    <p>歡迎對此專案提出問題和合併請求。如果您發現任何錯誤或有改進的建議,請隨時提出。</p>

 <h2>致謝</h2>
    <p>此專案採用 Claude 和 ChatGPT 協力完成。並由 Mark 測試及部分修正,同時感謝 鑫晟數位股份有限公司 願意且同意本人將此程式無償貢獻於網路社群!</p>
