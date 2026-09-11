# Sports Platform

BMIT3173 Integrative Programming — plain PHP (XAMPP).

## How to start (NetBeans + XAMPP)

1. Start **XAMPP** Control Panel → start **Apache** and **MySQL**.
2. Rebuild the database (double-click or run in Command Prompt):

```bat
database\setup.bat
```

3. Link the site into htdocs (run **Command Prompt as Administrator** once; change the path if your project folder is different):

```bat
mklink /J "C:\xampp\htdocs\sportsplatform" "C:\Users\<your-username>\AndroidStudioProjects\Y3S1-Integrative-Programming\public"
```

4. Open the project in **NetBeans**:
   - **File → Open Project…**
   - Select the folder `Y3S1-Integrative-Programming`
   - If NetBeans asks for a PHP project, choose **PHP Application with Existing Sources** and set **Source Folder** to this repo (or `public` as the web root if prompted).

5. In NetBeans, set **Run Configuration** (optional but useful):
   - **Project Properties → Run Configuration**
   - **Project URL:** `http://localhost/sportsplatform/`
   - Leave copy-to-htdocs off if you already used the junction in step 3.

6. Open a browser (or use NetBeans **Run**):  
   <http://localhost/sportsplatform/>

## Demo logins

Password for all seeded accounts: `Password123!`

| Email | Role |
|---|---|
| `aisyah.rahman@example.com` | Player |
| `contact@smashpoint.my` | Facility owner |
| `admin@sportsplatform.my` | Administrator |

## Payments (demo)

Internal demo only (Card / FPX / E-wallet). No real charges.  
Profile → **Payment history**, or open `payment.php`.