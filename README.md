# 📞 Leads Lite - Auto-Dialer System

A web-based lead management and auto-dialing system that integrates with MicroSIP for efficient cold calling campaigns. Built for sales teams to streamline their outbound calling workflow.

## 🎯 Features

### Core Functionality
- **Automated Dialing**: Upload leads via CSV and auto-dial through MicroSIP integration
- **Campaign Management**: Create and manage multiple calling campaigns
- **Real-time Call Tracking**: Track call outcomes (Interested, Not Interested, No Answer, Callback)
- **Lead Status Management**: Comprehensive lead lifecycle tracking
- **Session Management**: Active dial session monitoring with heartbeat system
- **FusionPBX Integration**: Call analytics, work hours tracking, and performance monitoring

### Lead Organization
- **Interested Leads**: Dedicated view for qualified prospects with follow-up scheduling
- **Callback Queue**: Manage leads requiring follow-up calls with date/time scheduling
- **Task Management**: Track follow-ups and action items
- **Notes & Chat**: Add detailed notes and conversation history for each lead

### User Management
- **Multi-User Support**: Role-based access (Admin/User)
- **Permission System**: Granular permissions for upload and delete operations
- **User Assignment**: Assign specific campaigns to team members
- **Performance Tracking**: Monitor individual user calling statistics
- **Extension Management**: Assign FusionPBX extensions to users for call tracking

### Reporting & Analytics
- **Performance Dashboard**: Track calls made, conversion rates, and time spent
- **Call History**: Complete audit trail of all dialing activity
- **CSV Export**: Download campaign results for external analysis
- **Session Reports**: View active and historical dial sessions
- **FusionPBX Call Reports**: Detailed call analytics with work hours compliance tracking
- **Hourly Breakdown**: Visual charts showing calling patterns throughout the day

## 🚀 Tech Stack

- **Backend**: PHP 8+ with PostgreSQL
- **Frontend**: Vanilla JavaScript, HTML5, CSS3
- **VoIP Integration**: MicroSIP (via `callto:` protocol) + FusionPBX
- **Database**: PostgreSQL with proper indexing and constraints
- **Session Management**: PHP sessions with secure cookie parameters

## 📋 Prerequisites

- PHP 8.0 or higher
- PostgreSQL 12+
- Web server (Apache/Nginx)
- MicroSIP installed on client machines
- FusionPBX (optional) - required ONLY for Call Reports & CDR. Core dialing works without it
- Modern web browser

## 🛠️ Installation

1. **Clone the repository**
```bash
   git clone https://github.com/datadine/microsip-auto-dialer.git
   cd microsip-auto-dialer
```

2. **Database Setup**
```bash
   # Create PostgreSQL database
   createdb dialerdb
   
   # Run schema creation
   psql -U postgres -d dialerdb -f database/schema.sql
   
   # Import FusionPBX schema (if using call analytics)
   psql -U postgres -d dialerdb -f fusionpbx/fusionpbx_schema.sql
```

3. **Configure Database Connection**
```bash
   # Copy example config files
   cp db.php.example db.php
   cp fusionpbx/fusionpbx_config.php.example fusionpbx/fusionpbx_config.php
   
   # Edit with your credentials
   nano db.php
   nano fusionpbx/fusionpbx_config.php
```

4. **Set Permissions**
```bash
   chmod 644 *.php
   chmod 600 db.php fusionpbx/fusionpbx_config.php  # Protect credentials
```

5. **Create Admin User**
```sql
   INSERT INTO users (username, password_hash, role, can_upload, can_delete, active)
   VALUES ('admin', crypt('your_password', gen_salt('bf')), 'admin', TRUE, TRUE, TRUE);
```

6. **Set up FusionPBX Sync (Optional)**
```bash
   # Add to crontab
   crontab -e
   
   # Add this line to sync every 5 minutes:
   */5 * * * * /usr/bin/php /var/www/leads/fusionpbx/sync_fusionpbx_calls.php >> /var/log/fusionpbx_sync.log 2>&1
```

## 📊 Database Schema

The system uses PostgreSQL with the following main tables:

### Core Tables
- `users` - User accounts and permissions
- `campaigns` - Calling campaigns
- `campaign_users` - User-to-campaign assignments
- `leads` - Lead contact information and status
- `call_logs` - Complete call history
- `interested_notes` - Qualified lead tracking
- `callback_notes` - Callback scheduling
- `dial_sessions` - Active calling session management
- `tasks` - Follow-up task management

### FusionPBX Integration Tables
- `fusionpbx_calls` - Synced call detail records from FusionPBX
- `fusionpbx_sync_log` - Sync operation tracking

## 🔧 Configuration

### Database Configuration
Edit `db.php` with your PostgreSQL credentials:
```php
$DB_HOST = '127.0.0.1';
$DB_PORT = '5432';
$DB_NAME = 'dialerdb';
$DB_USER = 'your_username';
$DB_PASS = 'your_password';
```

### FusionPBX Configuration
Edit `fusionpbx/fusionpbx_config.php` with your FusionPBX database credentials:
```php
$pdo_fusion = new PDO(
    "pgsql:host=127.0.0.1;port=5432;dbname=fusionpbx",
    "your_fusionpbx_user",
    "your_fusionpbx_password"
);
```

### MicroSIP Integration
The system uses the `callto:` protocol to trigger MicroSIP dialing. Ensure MicroSIP is:
- Installed on all client machines
- Configured as the default handler for `callto:` URIs
- Connected to your SIP/VoIP provider

## 📖 Usage

### For Admins

1. **Create a Campaign**
   - Navigate to Campaigns → New Campaign
   - Upload CSV file (Format: Business Name, Phone Number)
   - Assign users to the campaign

2. **Manage Users**
   - Admin Panel → Users tab
   - Create users with appropriate permissions
   - Assign FusionPBX extensions to users
   - Assign campaigns to specific users

3. **View Analytics**
   - Navigate to Call Reports & Analytics
   - Filter by extension, date range, and call duration
   - Download CSV exports for further analysis

### For Users

1. **Start Dialing**
   - Select a campaign
   - Click "Start Dialing"
   - System automatically dials through MicroSIP

2. **Mark Call Outcomes**
   - After each call, select: Interested, Not Interested, No Answer, or Callback
   - Add notes if needed
   - System auto-advances to next lead

3. **Manage Follow-ups**
   - "Interested" tab: Track qualified leads
   - "Call Back" tab: Schedule and manage callbacks
   - Add detailed notes and set follow-up dates

4. **Track Performance**
   - "Call Reports" tab: View your calling statistics
   - Monitor work hours compliance (9-hour target per day)
   - Review hourly breakdown of calling activity

## 📁 File Structure
```
microsip-auto-dialer/
├── index.php                   # Main campaign/dialer interface
├── login.php                   # User authentication
├── admin.php                   # Admin dashboard
├── interested.php              # Interested leads management
├── callback.php                # Callback queue
├── tasks.php                   # Task management
├── myperf.php                  # Performance tracking
├── auth.php                    # Session/permission helpers
├── db.php                      # Database connection (excluded from repo)
├── db.php.example              # Example database config
├── nav.php                     # Shared navigation component
├── heartbeat.php               # Session keepalive endpoint
├── download.php                # CSV export handler
├── logout.php                  # Logout handler
├── jssip_min.js                # VoIP library (optional)
└── fusionpbx/                  # FusionPBX integration
    ├── call_reports.php        # Call analytics dashboard
    ├── call_reports_api.php    # Analytics API endpoints
    ├── sync_fusionpbx_calls.php # CDR sync script
    ├── fusionpbx_config.php    # FusionPBX DB config (excluded)
    ├── fusionpbx_config.php.example # Example config
    └── fusionpbx_schema.sql    # FusionPBX tables schema
```

## 🔐 Security Features

- Password hashing with `bcrypt`
- Session security with HTTP-only cookies
- CSRF protection on state-changing operations
- SQL injection prevention via prepared statements
- Role-based access control
- Automatic session timeout (5 minutes inactivity)
- **Credential files excluded from repository** (`.gitignore`)
- Template configuration files for secure setup

## 📈 Performance Optimizations

- PostgreSQL row-level locking for concurrent dialing
- Efficient indexing on frequently queried columns
- Automatic cleanup of stale sessions
- Optimized queries with proper JOINs
- Session heartbeat to prevent timeout during active calls
- UUID-based sync for FusionPBX (prevents missed calls)
- DISTINCT ON queries to eliminate duplicate records

## ⚠️ Known Limitations

### FusionPBX Integration
- Sync runs every 5 minutes (configurable via cron)
- Requires read-only access to FusionPBX database
- Timezone conversions assume EST (America/New_York)
- Maximum 1000 calls synced per cron run

### General System
- Requires MicroSIP on Windows clients
- CSV uploads limited by PHP `upload_max_filesize`
- Session timeout may interrupt long calls
- No built-in email/SMS notifications (planned)

## 🐛 Troubleshooting

### FusionPBX Sync Issues
```bash
# Check sync log
tail -50 /var/log/fusionpbx_sync.log

# Verify sync status
sudo -u postgres psql dialerdb -c "SELECT * FROM fusionpbx_sync_log ORDER BY id DESC LIMIT 5;"

# Force re-sync
sudo -u postgres psql dialerdb -c "TRUNCATE fusionpbx_sync_log;"
php /var/www/leads/fusionpbx/sync_fusionpbx_calls.php
```

### CSV Export Duplicates
The system uses `DISTINCT ON` to prevent duplicate entries when agents mark calls with multiple statuses. Only the most recent status is exported.

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📝 License

This project is open source and available under the [MIT License](LICENSE).

## 🚀 Roadmap / TODO

- [ ] Implement email integration for follow-ups
- [ ] Add advanced reporting/analytics dashboard
- [ ] Mobile-responsive UI improvements
      
## Good to have

- [ ] WebRTC integration (alternative to MicroSIP) was tried, but the call quality dropped
- [ ] Real-time call monitoring for supervisors
- [ ] Add bulk SMS capability
- [ ] Voicemail drop integration
- [ ] CRM integration (Salesforce, HubSpot)

## 📞 Support

For issues, questions, or contributions, please open an issue on GitHub at:
https://github.com/datadine/microsip-auto-dialer/issues

## 🙏 Acknowledgments

Built for sales teams who need efficient cold-calling workflows. Designed to maximize productivity and lead conversion rates with comprehensive call analytics.

---

**Note**: This system requires MicroSIP to be installed and configured on client machines for the auto-dialing functionality to work properly. FusionPBX integration is optional but recommended for detailed call analytics and performance tracking.
