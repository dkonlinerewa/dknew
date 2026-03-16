<?php
// ===== admin.php - Comprehensive Admin Panel =====
session_start();
require_once 'config.php';

// ===== INITIALIZATION & AUTHENTICATION =====
class AdminPanel {
    private $db;
    private $user;
    private $role;
    private $settings;

    public function __construct() {
        $this->db = db();
        $this->checkAuth();
        $this->loadUser();
        $this->loadSettings();
    }

    private function checkAuth() {
        if (isset($_GET['logout'])) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']
                );
            }
            session_destroy();
            header('Location: ?action=login');
            exit;
        }

        $public_pages = ['login', 'verify_qr'];
        $current_page = $_GET['page'] ?? 'login';

        if (!isset($_SESSION['admin_id']) && !in_array($current_page, $public_pages)) {
            header('Location: ?page=login');
            exit;
        }
    }

    private function loadUser() {
        if (isset($_SESSION['admin_id'])) {
            $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE id = ?");
            $stmt->bindValue(1, $_SESSION['admin_id']);
            $result = $stmt->execute();
            $this->user = $result->fetchArray(SQLITE3_ASSOC);
            if ($this->user) {
                $this->role = $this->user['role'];
            }
        }
    }

    private function loadSettings() {
        $settings = [];
        $result = $this->db->query("SELECT * FROM site_settings");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        $this->settings = $settings;
    }

    public function render() {
        $page = $_GET['tab'] ?? 'dashboard';

        // HTMX partial mode: return only the main content HTML (no full page wrapper)
        if (!empty($_GET['partial']) && isset($_SESSION['admin_id'])) {
            switch ($page) {
                case 'ops':        $this->renderOps(); break;
                case 'management': $this->renderManagement(); break;
                case 'profile':    $this->renderProfile(); break;
                case 'settings':
                    $hasTechPerm = isset($this->user['tech_permission']) && $this->user['tech_permission'] == 1;
                    $hasAdminPerm= isset($this->user['admin_permission']) && $this->user['admin_permission'] == 1;
                    if ($this->role === 'admin' || $hasTechPerm || $hasAdminPerm) {
                        $this->renderSettings();
                    } else {
                        http_response_code(403);
                        echo '<div class="p-8 text-red-600 font-bold">Access Denied.</div>';
                    }
                    break;
                default:           $this->renderDashboard();
            }
            exit;
        }
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Admin Panel - D K Associates</title>

            <!-- Tailwind CSS -->
            <script src="https://cdn.tailwindcss.com"></script>

            <!-- Alpine.js -->
            <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

            <!-- HTMX -->
            <script src="https://unpkg.com/htmx.org@1.9.10"></script>

            <!-- Chart.js -->
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

            <!-- SortableJS -->
            <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

            <!-- SimpleMDE Markdown Editor -->
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.css">
            <script src="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.js"></script>

            <!-- Pikaday -->
            <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/pikaday/css/pikaday.css">
            <script src="https://cdn.jsdelivr.net/npm/pikaday/pikaday.js"></script>

            <!-- Choices.js -->
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
            <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

            <!-- Font Awesome -->
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

            <!-- QR Code Generator -->
            <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>

            <!-- html2canvas for ID card download -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

            <style>
                [x-cloak] { display: none !important; }
                .kanban-column { min-height: 500px; }
                .drag-over { background-color: rgba(59, 130, 246, 0.1); }
                .sticky-note {
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    width: 250px;
                    background: #fff3cd;
                    border: 1px solid #ffeeba;
                    border-radius: 8px;
                    padding: 15px;
                    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                    z-index: 1000;
                    cursor: move;
                }
                .sticky-note.minimized {
                    width: auto;
                    height: auto;
                    padding: 10px 15px;
                }
                .sticky-note.minimized .note-content {
                    display: none;
                }
                .sticky-note-counter {
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    background: #0f3b5e;
                    color: white;
                    width: 50px;
                    height: 50px;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    cursor: pointer;
                    z-index: 999;
                }
                .id-card-preview {
                    width: 85.6mm;
                    height: 53.98mm;
                    background: white;
                    border: 1px solid #ccc;
                    border-radius: 3mm;
                    padding: 5mm;
                    position: relative;
                    font-family: Arial, sans-serif;
                }
                .id-card-preview .logo {
                    position: absolute;
                    top: 5mm;
                    left: 5mm;
                    width: 15mm;
                    height: 15mm;
                }
                .id-card-preview .photo {
                    position: absolute;
                    top: 5mm;
                    right: 5mm;
                    width: 20mm;
                    height: 20mm;
                    border-radius: 2mm;
                    object-fit: cover;
                }
                .id-card-preview .qr {
                    position: absolute;
                    bottom: 5mm;
                    right: 5mm;
                    width: 15mm;
                    height: 15mm;
                }
            </style>
        </head>
        <style>
            [x-cloak] { display: none !important; }
            #main-content { transition: opacity 0.15s ease; }
            #main-content.htmx-loading { opacity: 0.5; pointer-events: none; }
            /* Nav active state managed by JS to survive HTMX swaps */
            .nav-link { color: #d1d5db; }
            .nav-link:hover { background-color: #1f2937; color: white; }
            .nav-link.active { background-color: rgb(37 99 235); color: white; }
        </style>
        <body class="bg-gray-100 font-sans text-gray-800" x-data="app()">
            <!-- Loading Overlay -->
            <div x-show="loading" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
                <div class="animate-spin rounded-full h-32 w-32 border-b-2 border-white"></div>
            </div>

            <!-- Notification Toast -->
            <div x-show="notification.show"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 transform translate-x-full"
                 x-transition:enter-end="opacity-100 transform translate-x-0"
                 class="fixed top-4 right-4 z-50 max-w-sm bg-white rounded-lg shadow-lg p-4">
                <div class="flex items-center">
                    <div :class="'w-2 h-2 rounded-full mr-2 ' + notification.type"></div>
                    <p class="text-sm" x-text="notification.message"></p>
                    <button @click="notification.show = false" class="ml-4 text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <?php if (isset($_SESSION['admin_id'])): ?>
            <!-- Sidebar -->
            <div class="fixed inset-y-0 left-0 w-64 bg-gray-900 text-white overflow-y-auto">
                <div class="p-4 border-b border-gray-700">
                    <?php
                    $logo = $this->settings['site_logo'] ?? '';
                    $siteTitle = htmlspecialchars($this->settings['site_title'] ?? 'Admin Panel');
                    $isUrl = filter_var($logo, FILTER_VALIDATE_URL);
                    $isPath = !empty($logo) && $logo[0] === '/' && file_exists(dirname(__DIR__) . $logo);
                    ?>
                    <div class="flex items-center gap-3 mb-2">
                        <?php if ($isUrl || $isPath): ?>
                        <img src="<?php echo htmlspecialchars($logo); ?>" alt="Logo" class="h-8 w-auto object-contain" onerror="this.remove()">
                        <?php elseif (!empty($logo) && mb_strlen($logo) <= 4): ?>
                        <span class="text-2xl"><?php echo htmlspecialchars($logo); ?></span>
                        <?php endif; ?>
                        <h1 class="text-lg font-bold truncate"><?php echo $siteTitle; ?></h1>
                    </div>
                    <p class="text-xs text-gray-400">Welcome, <?php echo htmlspecialchars($this->user['full_name']); ?></p>
                    <p class="text-xs text-gray-500"><?php echo ucfirst($this->role); ?></p>
                </div>

                <nav class="mt-4">
                    <?php
                    $menu_items = [
                        'dashboard' => ['icon' => 'fa-gauge-high', 'label' => 'Dashboard'],
                        'ops'       => ['icon' => 'fa-gears',       'label' => 'Operations'],
                        'management'=> ['icon' => 'fa-chart-line',  'label' => 'Management'],
                        'profile'   => ['icon' => 'fa-user',        'label' => 'Staff Hub']
                    ];

                    // Settings: admin role only
                    if ($this->role === 'admin') {
                        $menu_items['settings'] = ['icon' => 'fa-gear', 'label' => 'Settings'];
                    }

                    foreach ($menu_items as $key => $item):
                        $active = ($page === $key) ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white';
                    ?>
                    <a href="?tab=<?php echo $key; ?>"
                       hx-get="?tab=<?php echo $key; ?>&partial=1"
                       hx-target="#main-content"
                       hx-swap="innerHTML"
                       hx-push-url="?tab=<?php echo $key; ?>"
                       hx-on:htmx:before-request="document.getElementById('main-content').classList.add('htmx-loading')"
                       hx-on:htmx:after-settle="document.getElementById('main-content').classList.remove('htmx-loading')"
                       class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-800 hover:text-white transition rounded-md mx-2 my-0.5 nav-link"
                       data-tab="<?php echo $key; ?>"
                       onclick="setActiveNav(this)"
                       >
                        <i class="fas <?php echo $item['icon']; ?> w-6"></i>
                        <span><?php echo $item['label']; ?></span>

                        <?php if ($key === 'ops' && $this->getUnreadCount() > 0): ?>
                        <span class="ml-auto bg-red-500 text-xs px-2 py-1 rounded-full">
                            <?php echo $this->getUnreadCount(); ?>
                        </span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </nav>

                <div class="absolute bottom-0 left-0 right-0 p-4">
                    <a href="?logout=1" class="flex items-center px-4 py-2 hover:bg-gray-800 rounded">
                        <i class="fas fa-sign-out-alt w-6"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>

            <!-- Main Content (HTMX target) -->
            <div id="main-content" class="ml-64 p-6 min-w-0" style="width:calc(100vw - 16rem); max-width:calc(100vw - 16rem); overflow-x:hidden;">
                <?php
                switch ($page) {
                    case 'dashboard':
                        $this->renderDashboard();
                        break;
                    case 'ops':
                        $this->renderOps();
                        break;
                    case 'management':
                        $this->renderManagement();
                        break;
                    case 'profile':
                        $this->renderProfile();
                        break;
                    case 'settings':
                        $this->renderSettings();
                        break;
                    default:
                        $this->renderDashboard();
                }
                ?>
            </div><!-- end #main-content -->

            <?php if ($this->role !== 'staff' || $this->user['care_permission'] == 1): ?>
<div x-data="chatWidget()" class="fixed bottom-4 right-4 z-40">
    <!-- Chat Toggle -->
    <button @click="toggleChat"
            class="bg-blue-600 text-white rounded-full w-14 h-14 shadow-lg hover:bg-blue-700 transition relative">
        <i class="fas fa-comment" x-show="!isOpen"></i>
        <i class="fas fa-times" x-show="isOpen"></i>
        <span x-show="queueCount > 0 && !isOpen" 
              class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center"
              x-text="queueCount"></span>
    </button>

    <!-- Chat Window -->
    <div x-show="isOpen"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 transform scale-95"
         x-transition:enter-end="opacity-100 transform scale-100"
         class="absolute bottom-16 right-0 w-[32rem] bg-white rounded-lg shadow-xl border">

        <!-- Header with Tabs -->
        <div class="bg-blue-600 text-white p-3 rounded-t-lg">
            <div class="flex justify-between items-center mb-2">
                <h3 class="font-bold">Team Communicator</h3>
                <button @click="isOpen = false" class="text-white hover:text-gray-200">
                    <i class="fas fa-minus"></i>
                </button>
            </div>
            <div class="flex space-x-1 text-sm">
                <button @click="activeTab = 'guest'; loadMessages();"
                        :class="{'bg-blue-700': activeTab === 'guest'}"
                        class="px-3 py-1 rounded flex-1">
                    Guest Chat <span x-show="queueCount > 0" class="ml-1 bg-red-500 px-1.5 rounded-full text-xs" x-text="queueCount"></span>
                </button>
                <button @click="activeTab = 'staff'; loadMessages();"
                        :class="{'bg-blue-700': activeTab === 'staff'}"
                        class="px-3 py-1 rounded flex-1">
                    Staff
                </button>
                <button @click="activeTab = 'team'; loadMessages();"
                        :class="{'bg-blue-700': activeTab === 'team'}"
                        class="px-3 py-1 rounded flex-1">
                    Team
                </button>
                <button @click="activeTab = 'broadcast'; loadMessages();"
                        :class="{'bg-blue-700': activeTab === 'broadcast'}"
                        class="px-3 py-1 rounded flex-1">
                    Broadcast
                </button>
            </div>
        </div>

        <!-- Session List for Guest Chat -->
        <div x-show="activeTab === 'guest'" class="border-b max-h-32 overflow-y-auto bg-gray-50">
            <template x-for="session in guestSessions" :key="session.session_id">
                <div @click="selectSession(session.session_id)"
                     :class="{'bg-blue-100': selectedSession === session.session_id}"
                     class="px-4 py-2 cursor-pointer hover:bg-gray-100 flex justify-between items-center border-b last:border-b-0">
                    <div class="flex-1">
                        <div class="flex items-center">
                            <span class="font-medium text-sm" x-text="session.guest_name || 'Guest'"></span>
                            <span x-show="session.unread_count > 0" 
                                  class="ml-2 bg-red-500 text-white text-xs rounded-full px-2 py-0.5"
                                  x-text="session.unread_count"></span>
                        </div>
                        <div class="text-xs text-gray-500 flex items-center">
                            <span x-text="session.contact_reason || 'General'"></span>
                            <span class="mx-1">•</span>
                            <span x-text="session.last_activity_formatted"></span>
                        </div>
                        <div x-show="session.last_message" class="text-xs text-gray-600 truncate max-w-xs" x-text="session.last_message"></div>
                    </div>
                    <button @click.stop="terminateSession(session.session_id)" 
                            class="text-red-500 hover:text-red-700 text-xs ml-2"
                            title="Terminate Chat">
                        <i class="fas fa-times-circle"></i>
                    </button>
                </div>
            </template>
            <div x-show="guestSessions.length === 0" class="px-4 py-3 text-sm text-gray-500 text-center">
                No active guest chats
            </div>
        </div>

        <!-- Messages Area -->
        <div class="h-96 overflow-y-auto p-4 bg-gray-50" x-ref="messages">
            <template x-for="msg in messages" :key="msg.id || msg.temp_id">
                <div :class="{'flex justify-end': msg.sender_type === 'admin' || msg.sender_type === 'system'}"
                     class="mb-3">
                    <div :class="{
                            'bg-blue-600 text-white': msg.sender_type === 'admin',
                            'bg-gray-300 text-gray-800': msg.sender_type === 'system',
                            'bg-gray-200 text-gray-800': msg.sender_type !== 'admin' && msg.sender_type !== 'system',
                            'opacity-50': msg.is_temp
                         }"
                         class="inline-block p-3 rounded-lg max-w-xs shadow-sm">
                        <p class="text-xs font-bold mb-1 flex items-center">
                            <span x-text="msg.sender_name || (msg.sender_type === 'admin' ? 'You' : 'Guest')"></span>
                            <span x-show="msg.sender_type === 'system'" class="ml-1 text-xs">(System)</span>
                        </p>
                        <p class="text-sm break-words" x-text="msg.message"></p>
                        <div class="flex justify-end items-center mt-1 space-x-1">
                            <p class="text-xs opacity-75" x-text="msg.time"></p>
                            <i x-show="msg.is_read && msg.sender_type === 'admin'" class="fas fa-check-double text-xs text-green-300"></i>
                            <i x-show="!msg.is_read && msg.sender_type === 'admin'" class="fas fa-check text-xs"></i>
                        </div>
                    </div>
                </div>
            </template>
            <div x-show="messages.length === 0" class="text-center text-gray-400 py-8">
                No messages yet. Start the conversation!
            </div>
        </div>

        <!-- Input Area -->
        <div class="p-3 border-t bg-white">
            <form @submit.prevent="sendMessage">
                <div class="flex space-x-2">
                    <input type="text"
                           x-model="newMessage"
                           :placeholder="'Type your ' + (activeTab === 'broadcast' ? 'broadcast' : activeTab) + ' message...'"
                           class="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-600"
                           :disabled="activeTab === 'guest' && !selectedSession">
                    <button type="submit"
                            :disabled="activeTab === 'guest' && !selectedSession"
                            class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
                
                <!-- Staff Selector -->
                <div x-show="activeTab === 'staff'" class="mt-2">
                    <select id="staffSelect" x-model="receiverId" class="w-full text-sm border rounded px-2 py-1">
                        <option value="">Select staff member...</option>
                        <template x-for="staff in staffList" :key="staff.id">
                            <option :value="staff.id" x-text="staff.full_name + ' (' + staff.role + ')'"></option>
                        </template>
                    </select>
                </div>
                
                <!-- Attachment Button -->
                <div class="mt-2 flex justify-between items-center">
                    <button type="button" @click="document.getElementById('chatFile').click()"
                            class="text-gray-500 hover:text-blue-600 text-sm">
                        <i class="fas fa-paperclip mr-1"></i>Attach File
                    </button>
                    <span x-show="activeTab === 'guest' && !selectedSession" class="text-xs text-red-500">
                        Select a guest session first
                    </span>
                </div>
                <input type="file" id="chatFile" class="hidden" @change="uploadFile">
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

            <?php else: ?>
            <!-- Login Page -->
            <?php $this->renderLogin(); ?>
            <?php endif; ?>

            <script>
                function app() {
                    return {
                        loading: false,
                        notification: {
                            show: false,
                            message: '',
                            type: 'bg-green-500'
                        },
                        activeNotes: [],
                        noteCount: 0,

                        init() {
                            if (document.getElementById('main-content')) {
                                this.loadNotes();
                            }
                            // Remove htmx-loading class reliably on every settle/error
                            const clearLoading = () => {
                                const mc = document.getElementById('main-content');
                                if (mc) mc.classList.remove('htmx-loading');
                            };
                            document.body.addEventListener('htmx:afterSettle', clearLoading);
                            document.body.addEventListener('htmx:afterSwap',   clearLoading);
                            document.body.addEventListener('htmx:responseError', clearLoading);
                            document.body.addEventListener('htmx:sendError',    clearLoading);
                        },

                        showNotification(message, type = 'success') {
                            this.notification.message = message;
                            this.notification.type = type === 'success' ? 'bg-green-500' : 'bg-red-500';
                            this.notification.show = true;

                            setTimeout(() => {
                                this.notification.show = false;
                            }, 3000);
                        },

                        confirmAction(message) {
                            return confirm(message);
                        },

                        loadNotes() {
                            fetch('api/notes.php?active=1')
                                .then(res => res.json())
                                .then(data => {
                                    this.activeNotes = data;
                                    this.noteCount = data.length;
                                });
                        },

                        addNote(content) {
                            fetch('api/notes.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ note: content })
                            })
                            .then(res => res.json())
                            .then(() => {
                                this.loadNotes();
                                this.showNotification('Note added');
                            });
                        },

                        closeNote(id) {
                            fetch('api/notes.php', {
                                method: 'DELETE',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ id: id })
                            })
                            .then(() => {
                                this.loadNotes();
                            });
                        }
                    }
                }

                function chatWidget() {
    return {
        isOpen: false,
        activeTab: 'guest',
        messages: [],
        newMessage: '',
        receiverType: 'guest',
        selectedSession: '',
        guestSessions: [],
        queueCount: 0,
        pollingInterval: null,
        lastMessageId: 0,
        currentChatId: null,
        expandedSessions: {},
        
        init() {
            this.loadMessages();
            this.loadGuestSessions();
            this.startPolling();
            
            // Auto-scroll to bottom when messages update
            this.$watch('messages', () => {
                this.$nextTick(() => {
                    let container = this.$refs.messages;
                    if (container) container.scrollTop = container.scrollHeight;
                });
            });
        },
        
        toggleChat() {
            this.isOpen = !this.isOpen;
            if (this.isOpen) {
                this.loadMessages();
                this.loadGuestSessions();
                this.startPolling();
            } else {
                this.stopPolling();
            }
        },
        
        loadMessages() {
            let url = `api/chat.php?type=${this.activeTab}`;
            if (this.activeTab === 'guest' && this.selectedSession) {
                url += `&session_id=${this.selectedSession}`;
                // Reset last message ID when switching sessions
                this.lastMessageId = 0;
            }
            
            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (Array.isArray(data)) {
                        this.messages = data;
                        if (data.length > 0) {
                            this.lastMessageId = data[data.length - 1].id;
                        }
                    }
                })
                .catch(err => console.error('Error loading messages:', err));
        },
        
        loadGuestSessions() {
            fetch('api/chat.php?type=guest_sessions')
                .then(res => res.json())
                .then(data => {
                    this.guestSessions = data;
                    // Auto-select first session if none selected
                    if (this.guestSessions.length > 0 && !this.selectedSession) {
                        this.selectedSession = this.guestSessions[0].session_id;
                        this.loadMessages();
                    }
                })
                .catch(err => console.error('Error loading sessions:', err));
        },
        
        selectSession(sessionId) {
            this.selectedSession = sessionId;
            this.lastMessageId = 0;
            this.loadMessages();
            this.markSessionRead(sessionId);
        },
        
        markSessionRead(sessionId) {
            fetch('api/chat.php', {
                method: 'PUT',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ session_id: sessionId })
            }).catch(err => console.error('Error marking read:', err));
        },
        
        terminateSession(sessionId) {
            if (confirm('Are you sure you want to terminate this chat? The guest will be notified.')) {
                fetch('api/chat.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ 
                        action: 'terminate_session',
                        session_id: sessionId 
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        this.loadGuestSessions();
                        if (this.selectedSession === sessionId) {
                            this.selectedSession = '';
                            this.messages = [];
                        }
                        window.appNotify('Chat terminated');
                    }
                })
                .catch(err => console.error('Error terminating session:', err));
            }
        },
        
        sendMessage() {
            if (!this.newMessage.trim()) return;

            let tempId = 'temp_' + Date.now() + '_' + Math.random().toString(36);
            let data = {
                message: this.newMessage,
                type: this.receiverType,
                temp_id: tempId
            };
            
            if (this.receiverType === 'guest' && this.selectedSession) {
                data.session_id = this.selectedSession;
            }
            if (this.receiverType === 'staff') {
                let staffSelect = document.querySelector('#staffSelect');
                data.receiver_id = staffSelect ? staffSelect.value : 0;
            }

            // Optimistically add message to UI
            let optimisticMessage = {
                id: tempId,
                message: this.newMessage,
                sender_type: 'admin',
                sender_name: 'You',
                time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                is_temp: true
            };
            this.messages.push(optimisticMessage);
            let messageText = this.newMessage;
            this.newMessage = '';

            fetch('api/chat.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(res => res.json())
            .then(response => {
                // Remove temp message and add real one
                this.messages = this.messages.filter(m => m.id !== tempId);
                if (response.success) {
                    // Real message will come in next poll
                    this.loadMessages();
                } else {
                    window.appNotify('Failed to send message', 'error');
                }
            })
            .catch(err => {
                console.error('Error sending message:', err);
                this.messages = this.messages.filter(m => m.id !== tempId);
                window.appNotify('Network error', 'error');
            });
        },
        
        startPolling() {
            if (this.pollingInterval) clearInterval(this.pollingInterval);
            this.pollingInterval = setInterval(() => {
                this.pollForNewMessages();
                if (this.activeTab === 'guest') {
                    this.loadGuestSessions();
                }
            }, 2000); // Poll every 2 seconds
        },
        
        pollForNewMessages() {
            if (!this.selectedSession && this.activeTab === 'guest') return;
            
            let url = `api/chat.php?type=${this.activeTab}`;
            if (this.activeTab === 'guest' && this.selectedSession) {
                url += `&session_id=${this.selectedSession}`;
            }
            url += `&since_id=${this.lastMessageId}`;
            
            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (data.messages && data.messages.length > 0) {
                        // Add only new messages
                        this.messages = [...this.messages, ...data.messages];
                        this.lastMessageId = data.last_id;
                        
                        // Auto-scroll to bottom
                        this.$nextTick(() => {
                            let container = this.$refs.messages;
                            if (container) container.scrollTop = container.scrollHeight;
                        });
                        
                        // Mark as read
                        if (this.selectedSession) {
                            this.markSessionRead(this.selectedSession);
                        }
                    }
                })
                .catch(err => console.error('Error polling messages:', err));
        },
        
        stopPolling() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
                this.pollingInterval = null;
            }
        },
        
        toggleSessionExpand(sessionId) {
            this.expandedSessions[sessionId] = !this.expandedSessions[sessionId];
        },
        
        formatTime(timestamp) {
            return new Date(timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },
        
        getUnreadCount(session) {
            return session.unread_count || 0;
        }
    }
}

                // HTMX Configuration
                document.addEventListener('htmx:beforeRequest', function() {
                    const appEl = document.getElementById('app');
                    if (appEl && appEl._x_dataStack) Alpine.evaluate(appEl, 'loading = true');
                });

                document.addEventListener('htmx:afterRequest', function() {
                    const appEl = document.getElementById('app');
                    if (appEl && appEl._x_dataStack) Alpine.evaluate(appEl, 'loading = false');
                });

                document.addEventListener('htmx:responseError', function(evt) {
                    window.appNotify('An error occurred', 'error');
                });

                // Alpine v3 compatible global notification helper
                window.appNotify = function(message, type) {
                    const appEl = document.getElementById('app');
                    if (appEl && appEl._x_dataStack) {
                        Alpine.evaluate(appEl, `showNotification('${message.replace(/'/g,"\\'")}', '${type || 'success'}')`);
                    }
                };

                // Nav active state — survives HTMX swaps since sidebar is never swapped
                function setActiveNav(el) {
                    document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
                    el.classList.add('active');
                }
                // Set initial active from URL on page load
                (function() {
                    const params = new URLSearchParams(window.location.search);
                    const tab = params.get('tab') || 'dashboard';
                    const link = document.querySelector(`.nav-link[data-tab="${tab}"]`);
                    if (link) link.classList.add('active');
                    // Also mark active after HTMX pushes a new URL
                    document.body.addEventListener('htmx:pushedIntoHistory', function() {
                        const t = new URLSearchParams(window.location.search).get('tab') || 'dashboard';
                        document.querySelectorAll('.nav-link').forEach(a => {
                            a.classList.toggle('active', a.dataset.tab === t);
                        });
                    });
                })();
            </script>
        </body>
        </html>
        <?php
    }

    private function renderDashboard() {
        ?>
        <div x-data="dashboard()" x-init="init()">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-bold">Command Center</h1>
                <div class="flex space-x-2">
                    <button @click="punchInOut"
                            :class="isPunchedIn ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700'"
                            class="text-white px-4 py-2 rounded-lg transition">
                        <i :class="isPunchedIn ? 'fas fa-sign-out-alt' : 'fas fa-sign-in-alt'" class="mr-2"></i>
                        <span x-text="isPunchedIn ? 'Punch Out' : 'Punch In'"></span>
                    </button>
                    <button @click="showAddNote = true" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                        <i class="fas fa-sticky-note mr-2"></i>Add Note
                    </button>
                </div>
            </div>

            <!-- Sticky Notes Display -->
            <div x-show="activeNotes.length > 0" class="fixed bottom-4 right-4 z-50">
                <template x-for="(note, index) in activeNotes" :key="note.id">
                    <div class="sticky-note mb-2" :style="'background-color: ' + note.color + ';'">
                        <div class="flex justify-between items-center mb-2">
                            <i class="fas fa-sticky-note"></i>
                            <button @click="closeNote(note.id)" class="text-gray-600 hover:text-red-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="note-content" x-text="note.content"></div>
                        <div class="text-xs text-gray-500 mt-2" x-text="new Date(note.created_at).toLocaleString()"></div>
                    </div>
                </template>
            </div>

            <!-- Summary Widgets -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <?php
                $widgets = [
                    ['title' => 'Tasks', 'icon' => 'fa-tasks', 'color' => 'blue',
                     'count' => $this->getTaskCounts()],
                    ['title' => 'Applications', 'icon' => 'fa-file-alt', 'color' => 'green',
                     'count' => $this->getApplicationCounts()],
                    ['title' => 'Enquiries', 'icon' => 'fa-question-circle', 'color' => 'yellow',
                     'count' => $this->getEnquiryCounts()],
                    ['title' => 'Workers', 'icon' => 'fa-users', 'color' => 'purple',
                     'count' => $this->getWorkerCounts()]
                ];

                foreach ($widgets as $widget):
                ?>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm"><?php echo $widget['title']; ?></p>
                            <p class="text-2xl font-bold">
                                <?php echo $widget['count']['total'] ?? 0; ?>
                                <?php if (isset($widget['count']['pending'])): ?>
                                <span class="text-sm text-gray-400 ml-2">
                                    (<?php echo $widget['count']['pending']; ?> pending)
                                </span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="bg-<?php echo $widget['color']; ?>-100 p-3 rounded-lg">
                            <i class="fas <?php echo $widget['icon']; ?> text-<?php echo $widget['color']; ?>-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- 3-Month Calendar -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold">Interactive Calendar</h2>
                    <div class="flex space-x-2">
                        <button @click="changeMonth(-1)" class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">
                            <i class="fas fa-chevron-left"></i> Previous
                        </button>
                        <button @click="changeMonth(0)" class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700">
                            Current
                        </button>
                        <button @click="changeMonth(1)" class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">
                            Next <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <template x-for="(month, index) in months" :key="index">
                            <div>
                                <h3 class="font-bold text-center mb-2 text-sm" x-text="month.name"></h3>
                                <div class="grid grid-cols-7 gap-0.5 text-xs">
                                    <template x-for="d in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="d">
                                        <div class="text-center font-semibold text-gray-400 py-1" x-text="d.charAt(0)"></div>
                                    </template>
                                    <template x-for="day in month.days" :key="(day.full_date || 'pad_') + day.date">
                                        <div @click="day.date && selectDate(day)"
                                             :title="day.full_date ? (day.status || 'No record') : ''"
                                             :style="day.date ? calendarCellStyle(day.status) : ''"
                                             :class="[
                                                'text-center py-1 rounded text-xs',
                                                day.date ? 'cursor-pointer hover:opacity-80' : 'invisible',
                                                !day.status ? 'bg-white text-gray-700 border border-gray-100' : ''
                                             ]">
                                            <span x-text="day.date || ''"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Pending Approvals -->
                    <template x-if="pendingApprovals.length > 0">
                        <div class="mt-6 p-4 bg-orange-50 border border-orange-200 rounded-lg">
                            <h4 class="font-bold text-orange-800 mb-2"><i class="fas fa-exclamation-circle mr-2"></i>Pending Approvals</h4>
                            <div class="space-y-2">
                                <template x-for="event in pendingApprovals" :key="event.id">
                                    <div class="flex justify-between items-center bg-white p-2 rounded shadow-sm">
                                        <div class="text-sm">
                                            <span class="font-bold" x-text="event.title"></span> on <span x-text="event.start_date"></span>
                                            <p class="text-xs text-gray-500" x-text="event.description"></p>
                                        </div>
                                        <div class="flex space-x-2">
                                            <button @click="approveEvent(event.id)" class="bg-green-600 text-white px-3 py-1 rounded text-xs hover:bg-green-700">Approve</button>
                                            <button @click="showRejectReason(event.id)" class="bg-red-600 text-white px-3 py-1 rounded text-xs hover:bg-red-700">Reject</button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <!-- Legend -->
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span class="flex items-center"><span class="w-3 h-3 bg-green-200 rounded mr-1"></span> On-time</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-yellow-200 rounded mr-1"></span> Late</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-red-200 rounded mr-1"></span> Early</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-purple-200 rounded mr-1"></span> Leave</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-blue-200 rounded mr-1"></span> Holiday</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-gray-200 rounded mr-1"></span> Week-off</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-orange-200 rounded mr-1"></span> Event</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-amber-200 rounded mr-1"></span> Expiry</span>
                    </div>
                </div>
            </div>

            <!-- Activity Feed -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-4 border-b">
                    <h2 class="text-xl font-bold">Recent Activity</h2>
                </div>
                <div class="p-4">
                    <div class="space-y-3" x-html="activityFeed"></div>
                </div>
            </div>

            <!-- Quick Note Modal -->
            <div x-show="showAddNote" @click.outside="showAddNote = false" @keydown.escape.window="showAddNote = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Add Quick Note</h3>
                    <textarea x-model="newNote" class="w-full border rounded p-2 h-32 mb-4" placeholder="Type your note..."></textarea>
                    <div class="flex justify-end space-x-2">
                        <button @click="showAddNote = false" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                        <button @click="saveNote" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
                    </div>
                </div>
            </div>

            <!-- Reject Reason Modal -->
            <div x-show="showRejectModal" @click.outside="showRejectModal = false" @keydown.escape.window="showRejectModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Rejection Reason</h3>
                    <textarea x-model="rejectReason" class="w-full border rounded p-2 h-32 mb-4" placeholder="Please provide reason for rejection (minimum 2 words)"></textarea>
                    <div class="flex justify-end space-x-2">
                        <button @click="showRejectModal = false" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                        <button @click="confirmReject" class="px-4 py-2 bg-red-600 text-white rounded">Reject</button>
                    </div>
                </div>
            </div>

            <!-- Calendar Action Choice Modal -->
            <div x-show="showCalendarActions" @click.outside="showCalendarActions = false" @keydown.escape.window="showCalendarActions = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60]" x-cloak>
                <div class="bg-white rounded-lg p-6 w-80 shadow-xl">
                    <h3 class="text-lg font-bold mb-4 text-center">Calendar Actions</h3>
                    <p class="text-sm text-gray-600 mb-6 text-center">Action for <span class="font-bold" x-text="selectedDate"></span></p>
                    <div class="space-y-3">
                        <button @click="showCalendarActions = false; applyLeaveFromCalendar()" class="w-full py-2 bg-purple-600 text-white rounded hover:bg-purple-700 font-medium">
                            <i class="fas fa-plane-departure mr-2"></i>Apply Leave
                        </button>
                        <button @click="showCalendarActions = false; showReminderModal = true" class="w-full py-2 bg-blue-600 text-white rounded hover:bg-blue-700 font-medium">
                            <i class="fas fa-bell mr-2"></i>Add Reminder
                        </button>
                        <button @click="showCalendarActions = false" class="w-full py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>

            <!-- Reminder Modal -->
            <div x-show="showReminderModal" @click.outside="showReminderModal = false" @keydown.escape.window="showReminderModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60]" x-cloak>
                <div class="bg-white rounded-lg p-6 w-96 shadow-xl">
                    <h3 class="text-lg font-bold mb-4">Add Reminder for <span x-text="selectedDate"></span></h3>
                    <div class="mb-3">
                        <label class="block text-sm font-medium mb-1">Title</label>
                        <input type="text" x-model="reminderForm.title" class="w-full border rounded px-3 py-2" placeholder="e.g. Follow up with client">
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium mb-1">Description</label>
                        <textarea x-model="reminderForm.description" class="w-full border rounded px-3 py-2" rows="3" placeholder="Additional details..."></textarea>
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button @click="showReminderModal = false" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                        <button @click="saveReminder" class="px-4 py-2 bg-blue-600 text-white rounded">Save Reminder</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function dashboard() {
                return {
                    isPunchedIn: false,
                    showAddNote: false,
                    showRejectModal: false,
                    newNote: '',
                    rejectReason: '',
                    currentEventId: null,
                    months: [],
                    activityFeed: '',
                    pendingApprovals: [],
                    currentMonthOffset: 0,
                    selectedDate: null,
                    showCalendarActions: false,
                    showReminderModal: false,
                    reminderForm: {
                        title: '',
                        description: ''
                    },

                    init() {
                        this.loadCalendar();
                        this.loadActivity();
                        this.checkPunchStatus();
                        this.loadPendingApprovals();
                    },

                    calendarCellStyle(status) {
                        const map = {
                            'ontime':  'background:#bbf7d0;color:#166534',   // green-200 / green-800
                            'late':    'background:#fef08a;color:#854d0e',   // yellow-200 / yellow-800
                            'early':   'background:#fecaca;color:#991b1b',   // red-200 / red-800
                            'leave':   'background:#e9d5ff;color:#6b21a8',   // purple-200 / purple-800
                            'holiday': 'background:#bfdbfe;color:#1e40af',   // blue-200 / blue-800
                            'weekoff': 'background:#e5e7eb;color:#374151',   // gray-200 / gray-700
                            'event':   'background:#fed7aa;color:#9a3412',   // orange-200 / orange-800
                            'expiry':  'background:#fde68a;color:#92400e',   // amber-200 / amber-800
                            'absent':  'background:#fca5a5;color:#7f1d1d',   // red-300 / red-900
                        };
                        return map[status] || 'background:#f9fafb;color:#374151';
                    },

                                        punchInOut() {
                        const doPunch = (lat, lng) => {
                            const deviceId = this.getDeviceId();
                            fetch('api/attendance.php?status=1')
                                .then(res => res.json())
                                .then(data => {
                                    const action = (data.punched_in && this.isPunchedIn) ? 'out' : 'in';
                                    return fetch('api/attendance.php', {
                                        method: 'POST',
                                        headers: {'Content-Type': 'application/json'},
                                        body: JSON.stringify({
                                            action: action,
                                            location: { lat: lat, lng: lng },
                                            device_id: deviceId
                                        })
                                    });
                                })
                                .then(r => r.json())
                                .then(data => {
                                    if (data.error) {
                                        if (data.geofence_failed) {
                                            alert(`📍 Outside office area!\nYou are ${data.distance}m away from the office. Allowed radius: ${data.radius}m.`);
                                        } else if (data.gps_required) {
                                            alert('📍 GPS location is required for attendance. Please enable location access in your browser settings and try again.');
                                        } else {
                                            alert(data.error);
                                        }
                                        return;
                                    }
                                    this.isPunchedIn = !this.isPunchedIn;
                                    const msg = this.isPunchedIn ? '✅ Punched In Successfully' : '✅ Punched Out Successfully';
                                    window.appNotify(msg);
                                    this.loadCalendar();
                                });
                        };

                        if (navigator.geolocation) {
                            navigator.geolocation.getCurrentPosition(
                                pos => doPunch(pos.coords.latitude, pos.coords.longitude),
                                () => doPunch(null, null) // If GPS denied, let server decide based on geofence config
                            );
                        } else {
                            doPunch(null, null);
                        }
                    },

                    getDeviceId() {
                        let deviceId = localStorage.getItem('device_id');
                        if (!deviceId) {
                            deviceId = 'device_' + Math.random().toString(36).substr(2, 9);
                            localStorage.setItem('device_id', deviceId);
                        }
                        return deviceId;
                    },

                    loadCalendar() {
                        // Load 3 months: previous, current, next (relative to currentMonthOffset)
                        const base = new Date();
                        const promises = [-1, 0, 1].map(delta => {
                            const d = new Date(base);
                            d.setDate(1);
                            d.setMonth(base.getMonth() + this.currentMonthOffset + delta);
                            const y = d.getFullYear();
                            const m = String(d.getMonth() + 1).padStart(2, '0');
                            return fetch(`api/calendar.php?month=${y}-${m}`)
                                .then(r => r.json())
                                .then(data => ({
                                    name: data.month_name || `${y}-${m}`,
                                    days: data.days || []
                                }))
                                .catch(() => ({ name: `${y}-${m}`, days: [] }));
                        });
                        Promise.all(promises).then(results => {
                            this.months = results;
                            // Also pick up pending approvals from current month
                            fetch(`api/calendar.php?month=${(() => {
                                const d = new Date(); d.setDate(1);
                                d.setMonth(base.getMonth() + this.currentMonthOffset);
                                return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
                            })()}`).then(r=>r.json()).then(data => {
                                this.pendingApprovals = data.pending_approvals || [];
                            }).catch(()=>{});
                        });
                    },

                    changeMonth(directionOrReset) {
                        if (directionOrReset === 0) {
                            this.currentMonthOffset = 0;
                        } else {
                            this.currentMonthOffset += directionOrReset;
                        }
                        this.loadCalendar();
                    },

                    loadActivity() {
                        fetch('api/activity.php')
                            .then(res => res.text())
                            .then(data => {
                                this.activityFeed = data;
                            });
                    },

                    checkPunchStatus() {
                        fetch('api/attendance.php?status=1')
                            .then(res => res.json())
                            .then(data => {
                                this.isPunchedIn = data.punched_in;
                            });
                    },

                    loadPendingApprovals() {
                        fetch('api/approvals.php?pending=1')
                            .then(res => res.json())
                            .then(data => {
                                this.pendingApprovals = data;
                            });
                    },

                    selectDate(day) {
                        if (day.status !== 'leave' && day.status !== 'holiday' && day.status !== 'weekoff') {
                            this.selectedDate = day.full_date;
                            this.showCalendarActions = true;
                        }
                    },

                    applyLeaveFromCalendar() {
                        if (confirm(`Do you want to apply for leave on ${this.selectedDate}?`)) {
                            fetch('api/leaves.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    start_date: this.selectedDate,
                                    end_date: this.selectedDate,
                                    type: 'Casual',
                                    reason: 'Leave applied from calendar'
                                })
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    window.appNotify('Leave applied successfully. Awaiting approval.');
                                    this.loadCalendar();
                                } else {
                                    alert(data.error || 'Failed to apply leave');
                                }
                            });
                        }
                    },

                    saveReminder() {
                        if (!this.reminderForm.title.trim()) return;
                        fetch('api/calendar.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                action: 'create',
                                title: this.reminderForm.title,
                                description: this.reminderForm.description,
                                event_type: 'general',
                                start_date: this.selectedDate,
                                target_type: 'specific',
                                target_ids: [<?php echo intval($_SESSION['admin_id']); ?>]
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showReminderModal = false;
                                this.reminderForm.title = '';
                                this.reminderForm.description = '';
                                window.appNotify('Reminder added to calendar');
                                this.loadCalendar();
                            } else {
                                alert(data.error || 'Failed to save reminder');
                            }
                        });
                    },

                    saveNote() {
                        if (!this.newNote.trim()) return;
                        fetch('api/notes.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ note: this.newNote })
                        }).then(r => r.json()).then(() => {
                            this.showAddNote = false;
                            this.newNote = '';
                            // Reload notes in the app-level component
                            const appEl = document.getElementById('app');
                            if (appEl && appEl._x_dataStack) {
                                Alpine.evaluate(appEl, 'loadNotes()');
                            }
                        }).catch(() => {});
                    },


                    approveEvent(eventId) {
                        if (confirm('Are you sure you want to approve this request?')) {
                            fetch('api/approvals.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ action: 'approve', id: eventId })
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.loadPendingApprovals();
                                    window.appNotify('Request approved');
                                }
                            });
                        }
                    },

                    showRejectReason(eventId) {
                        this.currentEventId = eventId;
                        this.rejectReason = '';
                        this.showRejectModal = true;
                    },

                    confirmReject() {
                        const words = this.rejectReason.trim().split(/\s+/);
                        if (words.length < 2) {
                            alert('Please provide at least 2 words for rejection reason');
                            return;
                        }

                        // Check if this is a pending_change rejection or a leave rejection
                        const isChange = String(this.currentEventId).startsWith('change_');
                        const numericId = isChange ? parseInt(String(this.currentEventId).replace('change_', '')) : this.currentEventId;
                        const action = isChange ? 'reject_change' : 'reject';

                        fetch('api/approvals.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ 
                                action: action, 
                                id: numericId,
                                reason: this.rejectReason
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showRejectModal = false;
                                this.rejectReason = '';
                                this.currentEventId = null;
                                this.loadPendingApprovals();
                                window.appNotify('Request rejected');
                            } else {
                                alert(data.error || 'Failed to reject request');
                            }
                        });
                    },

                    approveChange(changeId) {
                        if (!confirm('Approve this edit request? The changes will be applied immediately.')) return;
                        fetch('api/approvals.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ action: 'approve_change', id: changeId })
                        }).then(r => r.json()).then(data => {
                            if (data.success) {
                                this.loadPendingApprovals();
                                window.appNotify('Edit request approved and applied');
                            } else {
                                alert(data.error || 'Failed to approve change');
                            }
                        });
                    },

                    rejectChange(changeId) {
                        this.currentEventId = 'change_' + changeId;
                        this.showRejectModal = true;
                    },

                }
            }
        </script>
        <?php
    }

    private function renderOps() {
        ?>
        <div x-data="ops()" x-init="init()" style="max-width:100%;overflow-x:hidden">
            <h1 class="text-3xl font-bold mb-6">Operations Hub</h1>

            <!-- Tabs -->
            <div class="border-b mb-6">
                <nav class="flex space-x-4">
                    <button @click="activeTab = 'tasks'"
                            :class="{'border-b-2 border-blue-600 text-blue-600': activeTab === 'tasks'}"
                            class="px-4 py-2 font-medium">
                        Tasks
                    </button>
                    <?php if ($this->role === 'admin' || $this->role === 'manager'): ?>
                    <button @click="activeTab = 'approvals'"
                            :class="{'border-b-2 border-blue-600 text-blue-600': activeTab === 'approvals'}"
                            class="px-4 py-2 font-medium">
                        Approvals
                    </button>
                    <button @click="activeTab = 'users'"
                            :class="{'border-b-2 border-blue-600 text-blue-600': activeTab === 'users'}"
                            class="px-4 py-2 font-medium">
                        Users
                    </button>
                    <?php endif; ?>
                    <button @click="activeTab = 'directory'"
                            :class="{'border-b-2 border-blue-600 text-blue-600': activeTab === 'directory'}"
                            class="px-4 py-2 font-medium">
                        Directory
                    </button>
                    <button @click="activeTab = 'communicator'"
                            :class="{'border-b-2 border-blue-600 text-blue-600': activeTab === 'communicator'}"
                            class="px-4 py-2 font-medium">
                        Communicator
                    </button>
                </nav>
            </div>

            <!-- Tasks View (List Format) -->
            <div x-show="activeTab === 'tasks'">
                <div class="mb-4 flex justify-between items-center">
                    <button @click="showTaskModal = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>Create Task
                    </button>
                    <div class="flex space-x-2">
                        <select x-model="taskFilter" class="border rounded px-3 py-2 text-sm">
                            <option value="all">All Active Tasks</option>
                            <option value="pending">To Do</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Done</option>
                            <option value="review">Review</option>
                            <option value="archived">Archived Only</option>
                        </select>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Task</th>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Assigned To</th>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Due Date</th>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Priority</th>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <template x-for="task in filteredTasksList" :key="task.id">
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 cursor-pointer" @click="viewTask(task)">
                                        <div class="text-sm font-medium text-gray-900" x-text="task.title"></div>
                                        <div class="text-xs text-gray-500 truncate max-w-xs" x-text="task.description"></div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500" x-text="task.assigned_to_name"></td>
                                    <td class="px-4 py-3 text-sm text-gray-500" x-text="task.due_date"></td>
                                    <td class="px-4 py-3">
                                        <span :class="{
                                            'bg-gray-100 text-gray-800': task.status === 'pending',
                                            'bg-blue-100 text-blue-800': task.status === 'in_progress',
                                            'bg-green-100 text-green-800': task.status === 'completed',
                                            'bg-purple-100 text-purple-800': task.status === 'review',
                                            'bg-red-100 text-red-800': task.status === 'archived'
                                        }" class="px-2 py-1 rounded-full text-xs font-semibold"
                                        x-text="{pending:'To Do',in_progress:'In Progress',completed:'Done',review:'Review',archived:'Archived'}[task.status] || task.status"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span :class="{
                                            'text-red-600': task.priority === 'high',
                                            'text-yellow-600': task.priority === 'medium',
                                            'text-blue-600': task.priority === 'low'
                                        }" class="text-xs font-bold uppercase" x-text="task.priority"></span>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium">
                                        <div class="flex items-center space-x-2">
                                            <button @click.stop="viewTask(task)" title="View / Comment" class="text-blue-600 hover:text-blue-900">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button @click.stop="promptDeleteTask(task)" title="Delete Task" class="text-red-500 hover:text-red-700">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="filteredTasksList.length === 0">
                                <td colspan="6" class="text-center py-8 text-gray-400">No tasks found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Approvals View -->
            <div x-show="activeTab === 'approvals'">
                <div class="bg-white rounded-lg shadow">
                    <div class="p-4 border-b flex justify-between items-center">
                        <h3 class="font-bold">Pending Approvals</h3>
                        <button @click="loadApprovals()" class="text-sm text-blue-600 hover:underline">
                            <i class="fas fa-sync mr-1"></i>Refresh
                        </button>
                    </div>
                    <div class="p-4">
                        <!-- approvalsList is raw HTML from api/approvals.php -->
                        <!-- Buttons inside use onclick="window.opsApprove(id)" / window.opsReject(id, isChange) -->
                        <div x-show="!approvalsList" class="text-center text-gray-400 py-8">
                            <i class="fas fa-spinner fa-spin text-2xl mb-2 block"></i>
                            <p>Loading approvals...</p>
                        </div>
                        <table class="w-full" x-show="approvalsList">
                            <thead>
                                <tr class="text-left text-gray-600 border-b bg-gray-50">
                                    <th class="pb-3 px-3 font-semibold text-xs uppercase">Type</th>
                                    <th class="pb-3 px-3 font-semibold text-xs uppercase">Requester</th>
                                    <th class="pb-3 px-3 font-semibold text-xs uppercase">Details</th>
                                    <th class="pb-3 px-3 font-semibold text-xs uppercase">Date</th>
                                    <th class="pb-3 px-3 font-semibold text-xs uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody x-html="approvalsList"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Approval Action Modal - opened via window.opsApprove / window.opsReject -->
                <div x-show="showApprovalModal" @click.outside="showApprovalModal = false" @keydown.escape.window="showApprovalModal = false"
                     class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
                     x-cloak>
                    <div class="bg-white rounded-lg p-6 w-[420px] shadow-xl">
                        <h3 class="text-lg font-bold mb-1"
                            x-text="approvalAction === 'approve' ? '✅ Confirm Approval' : '❌ Confirm Rejection'"></h3>
                        <p class="text-sm text-gray-500 mb-4">
                            Request ID: <strong x-text="currentApprovalId"></strong>
                        </p>
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">
                                <span x-text="approvalAction === 'approve' ? 'Note (optional)' : 'Reason for Rejection *'"></span>
                            </label>
                            <textarea x-model="approvalReason"
                                      class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
                                      rows="3"
                                      :placeholder="approvalAction === 'approve'
                                          ? 'Add an approval note (optional)...'
                                          : 'Please provide a reason (minimum 2 words)...'"></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showApprovalModal = false; approvalReason = ''"
                                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200">
                                Cancel
                            </button>
                            <button @click="submitApprovalAction()"
                                    :class="approvalAction === 'approve'
                                        ? 'bg-green-600 hover:bg-green-700'
                                        : 'bg-red-600 hover:bg-red-700'"
                                    class="px-4 py-2 text-white rounded font-medium">
                                <span x-text="approvalAction === 'approve' ? 'Approve' : 'Reject'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Directory View -->
            <div x-show="activeTab === 'directory'">
                <div class="mb-4 flex space-x-2">
                    <button @click="showContactModal = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>Add Contact
                    </button>
                    <button @click="showAddWorker = true" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                        <i class="fas fa-user-plus mr-2"></i>Add Worker
                    </button>
                </div>
                <!-- Controls for listing Workers visual instead of the list being in a separate tab -->
                <div class="grid grid-cols-2 gap-6">
                    <!-- Contacts -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">Contacts Directory</h3>
                        </div>
                        <div class="p-4">
                            <input type="text"
                                   x-model="contactSearch"
                                   @input="searchContacts"
                                   placeholder="Search contacts..."
                                   class="w-full border rounded px-3 py-2 mb-4">
                            <div class="space-y-2" x-html="contactsList"></div>
                        </div>
                    </div>

                    <!-- Workers Directory Output -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">Workers Directory</h3>
                        </div>
                        <div class="p-4">
                            <input type="text"
                                   x-model="workerSearch"
                                   @input="searchWorkers"
                                   placeholder="Search workers..."
                                   class="w-full border rounded px-3 py-2 mb-4">
                            <div class="space-y-2" x-html="workersList"></div>
                            
                            <hr class="my-4">
                            <h4 class="font-bold mb-2 text-sm text-gray-500">Worker Cards</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 h-96 overflow-y-auto">
                                <template x-for="worker in workers" :key="worker.id">
                                    <div class="bg-gray-50 rounded-lg shadow-sm border p-3">
                                        <div class="flex items-center space-x-3 mb-2">
                                            <img :src="worker.photo_url || 'https://via.placeholder.com/50'"
                                                 class="w-10 h-10 rounded-full object-cover">
                                            <div>
                                                <h3 class="font-bold text-sm" x-text="worker.name"></h3>
                                                <p class="text-xs text-gray-600" x-text="worker.skills"></p>
                                            </div>
                                        </div>
                                        <div class="text-xs space-y-1 text-gray-600 mb-2">
                                            <p><i class="fas fa-phone w-4"></i> <span x-text="worker.phone"></span></p>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span :class="{
                                                'bg-green-100 text-green-800': worker.status === 'active',
                                                'bg-red-100 text-red-800': worker.status === 'inactive'
                                            }" class="px-2 py-1 rounded-full text-xs" x-text="worker.status"></span>

                                            <div class="flex space-x-2">
                                                <button @click="viewWorker(worker)" class="text-blue-600 hover:text-blue-800"><i class="fas fa-eye"></i></button>
                                                <button @click="generateIDCard(worker)" class="text-green-600 hover:text-green-800"><i class="fas fa-id-card"></i></button>
                                                <button @click="editWorker(worker)" class="text-yellow-600 hover:text-yellow-800"><i class="fas fa-edit"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Communicator View -->
<div x-show="activeTab === 'communicator'">
    <div class="bg-white rounded-lg shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-bold">Team Communications</h3>
            <div class="flex space-x-1 text-sm">
                <button @click="activeChatTab = 'guest'; fetchMessages(); loadAllGuestSessions();" 
                        :class="activeChatTab === 'guest' ? 'bg-blue-600 text-white' : 'bg-gray-200'"
                        class="px-3 py-1 rounded">
                    Guest Chat
                </button>
                <button @click="activeChatTab = 'staff'; fetchMessages(); loadStaffList();" 
                        :class="activeChatTab === 'staff' ? 'bg-blue-600 text-white' : 'bg-gray-200'"
                        class="px-3 py-1 rounded">
                    Staff
                </button>
                <button @click="activeChatTab = 'team'; fetchMessages();" 
                        :class="activeChatTab === 'team' ? 'bg-blue-600 text-white' : 'bg-gray-200'"
                        class="px-3 py-1 rounded">
                    Team
                </button>
                <button @click="activeChatTab = 'broadcast'; fetchMessages();" 
                        :class="activeChatTab === 'broadcast' ? 'bg-blue-600 text-white' : 'bg-gray-200'"
                        class="px-3 py-1 rounded">
                    Broadcast
                </button>
            </div>
        </div>
        
        <div class="p-4">
            <div class="grid grid-cols-4 gap-4">
                <!-- Session List (for guest chat) -->
                <div x-show="activeChatTab === 'guest'" class="col-span-1 border-r pr-4">
                    <h4 class="font-bold mb-3 text-sm">All Guest Sessions</h4>
                    <div class="space-y-2 max-h-[600px] overflow-y-auto">
                        <template x-for="session in allGuestSessions" :key="session.session_id">
                            <div @click="selectedGuestSession = session.session_id; fetchGuestMessages(session.session_id);"
                                 :class="{'bg-blue-100 border-blue-500': selectedGuestSession === session.session_id, 'border-gray-200': selectedGuestSession !== session.session_id}"
                                 class="border rounded-lg p-3 cursor-pointer hover:bg-gray-50 transition">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <span class="font-medium text-sm" x-text="session.guest_name || 'Anonymous Guest'"></span>
                                        <span x-show="session.status === 'active'" class="ml-2 text-xs bg-green-100 text-green-800 px-2 py-0.5 rounded-full">Active</span>
                                        <span x-show="session.status === 'terminated'" class="ml-2 text-xs bg-gray-100 text-gray-800 px-2 py-0.5 rounded-full">Terminated</span>
                                    </div>
                                    <span class="text-xs text-gray-500" x-text="session.last_activity_formatted"></span>
                                </div>
                                <div class="text-xs text-gray-600 mt-1" x-text="session.contact_reason || 'General Inquiry'"></div>
                                <div class="flex justify-between items-center mt-2">
                                    <span class="text-xs text-gray-500 truncate max-w-[150px]" x-text="session.last_message || 'No messages'"></span>
                                    <span x-show="session.unread_count > 0" class="bg-red-500 text-white text-xs rounded-full px-2 py-0.5" x-text="session.unread_count"></span>
                                </div>
                                <div class="mt-2 flex justify-end space-x-2">
                                    <button @click.stop="viewGuestDetails(session)" class="text-blue-600 text-xs hover:underline">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button x-show="session.status === 'active'" @click.stop="terminateGuestSession(session.session_id)" class="text-red-600 text-xs hover:underline">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                </div>
                            </div>
                        </template>
                        <div x-show="allGuestSessions.length === 0" class="text-center text-gray-400 py-8">
                            No guest sessions found
                        </div>
                    </div>
                </div>

                <!-- Messages Area -->
                <div :class="activeChatTab === 'guest' ? 'col-span-3' : 'col-span-4'">
                    <div class="border rounded-lg h-[500px] overflow-y-auto p-4 bg-gray-50" x-ref="chatMessages">
                        <template x-for="msg in teamMessages" :key="msg.id">
                            <div :class="{'flex justify-end': msg.sender_id === currentUserId && msg.sender_type === 'admin'}"
                                 class="mb-3">
                                <div :class="{
                                        'bg-blue-600 text-white': msg.sender_id === currentUserId && msg.sender_type === 'admin',
                                        'bg-gray-300 text-gray-800': msg.sender_type === 'system',
                                        'bg-gray-200 text-gray-800': !(msg.sender_id === currentUserId && msg.sender_type === 'admin') && msg.sender_type !== 'system'
                                     }"
                                     class="inline-block p-3 rounded-lg max-w-md shadow-sm">
                                    <p class="text-xs font-bold mb-1">
                                        <span x-text="msg.sender_name || (msg.sender_type === 'admin' ? 'You' : msg.sender_type === 'guest' ? 'Guest' : 'Staff')"></span>
                                        <span x-show="msg.sender_type === 'system'" class="ml-1 text-xs">(System)</span>
                                    </p>
                                    <p class="text-sm break-words" x-text="msg.message"></p>
                                    <div class="flex justify-end items-center mt-1">
                                        <p class="text-xs opacity-75" x-text="msg.time"></p>
                                        <i x-show="msg.is_read && msg.sender_type === 'admin'" class="fas fa-check-double text-xs ml-1 text-green-300"></i>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <div x-show="teamMessages.length === 0" class="text-center text-gray-400 py-8">
                            No messages yet
                        </div>
                    </div>

                    <!-- Input Area -->
                    <div class="mt-4">
                        <div class="flex space-x-2">
                            <template x-if="activeChatTab === 'staff'">
                                <select x-model="chatReceiverId" class="border rounded-lg px-3 py-2 w-48 text-sm">
                                    <option value="">Select Staff...</option>
                                    <template x-for="user in users" :key="user.id">
                                        <option :value="user.id" x-text="user.full_name"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="activeChatTab === 'guest'">
                                <select x-model="selectedGuestSession" class="border rounded-lg px-3 py-2 w-48 text-sm">
                                    <option value="">Select Session...</option>
                                    <template x-for="session in allGuestSessions" :key="session.session_id">
                                        <option :value="session.session_id" x-text="(session.guest_name || 'Guest') + ' - ' + (session.contact_reason || 'General')"></option>
                                    </template>
                                </select>
                            </template>
                            <input type="text"
                                   x-model="teamMessage"
                                   @keyup.enter="sendTeamMessage"
                                   :placeholder="'Type your ' + activeChatTab + ' message...'"
                                   class="flex-1 border rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-blue-600"
                                   :disabled="(activeChatTab === 'guest' && !selectedGuestSession) || (activeChatTab === 'staff' && !chatReceiverId)">
                            <button @click="sendTeamMessage" 
                                    :disabled="(activeChatTab === 'guest' && !selectedGuestSession) || (activeChatTab === 'staff' && !chatReceiverId)"
                                    class="bg-blue-600 text-white px-5 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

            <!-- Users Management View -->
            <div x-show="activeTab === 'users'">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold">User Management</h2>
                        <button @click="openAddUserModal()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                            <i class="fas fa-plus mr-2"></i>Add User
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="text-left border-b bg-gray-50 uppercase text-xs text-gray-500 font-medium">
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Email</th>
                                    <th class="px-4 py-3">Role</th>
                                    <th class="px-4 py-3">Department</th>
                                    <th class="px-4 py-3">Reporting Head</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <template x-for="u in users" :key="u.id">
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm font-medium text-gray-900" x-text="u.full_name"></td>
                                        <td class="px-4 py-3 text-sm text-gray-500" x-text="u.email"></td>
                                        <td class="px-4 py-3 text-sm text-gray-500 text-capitalize" x-text="u.role"></td>
                                        <td class="px-4 py-3 text-sm text-gray-500" x-text="u.department || 'N/A'"></td>
                                        <td class="px-4 py-3 text-sm text-gray-500" x-text="getReportingHeadName(u.reporting_head)"></td>
                                        <td class="px-4 py-3 text-sm">
                                            <span :class="u.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" class="px-2 py-1 rounded-full text-xs font-semibold" x-text="u.is_active ? 'Active' : 'Inactive'"></span>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <div class="flex space-x-2">
                                                <button @click="editUser(u)" class="text-blue-600 hover:text-blue-900"><i class="fas fa-edit"></i></button>
                                                <button @click="toggleUser(u)" :class="u.is_active ? 'text-yellow-600' : 'text-green-600'" class="hover:opacity-75"><i :class="u.is_active ? 'fas fa-ban' : 'fas fa-check-circle'"></i></button>
                                                <button @click="deleteUser(u.id)" class="text-red-600 hover:text-red-900"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Task Detail Popup -->
            <div x-show="showTaskDetail" @click.outside="showTaskDetail = false" @keydown.escape.window="showTaskDetail = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h3 class="text-2xl font-bold" x-text="currentTask?.title"></h3>
                            <p class="text-sm text-gray-500">Assigned by <span x-text="currentTask?.assigned_by_name"></span> on <span x-text="currentTask?.created_at"></span></p>
                        </div>
                        <button @click="showTaskDetail = false" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-3 gap-6 mb-4">
                        <div class="col-span-2">
                            <h4 class="font-bold mb-2">Description</h4>
                            <p class="text-gray-700 whitespace-pre-wrap mb-4 text-sm" x-text="currentTask?.description || 'No description.'"></p>

                            <h4 class="font-bold mb-2">Comments</h4>
                            <div class="space-y-3 mb-3 max-h-48 overflow-y-auto bg-gray-50 p-3 rounded border">
                                <template x-for="comment in taskComments" :key="comment.id">
                                    <div class="bg-white p-2 rounded shadow-sm border-l-4 border-blue-500">
                                        <div class="flex justify-between items-center mb-1">
                                            <span class="font-bold text-xs" x-text="comment.user_name"></span>
                                            <span class="text-[10px] text-gray-400" x-text="comment.created_at"></span>
                                        </div>
                                        <p class="text-sm text-gray-700" x-text="comment.comment"></p>
                                    </div>
                                </template>
                                <template x-if="taskComments.length === 0">
                                    <p class="text-xs text-gray-400 text-center py-4">No comments yet. Be the first to comment.</p>
                                </template>
                            </div>
                            <!-- Comment input — Enter to submit, button as backup -->
                            <div class="flex space-x-2">
                                <input type="text"
                                       x-model="newTaskComment"
                                       @keyup.enter="addTaskComment()"
                                       placeholder="Add a comment and press Enter or click Send..."
                                       class="flex-1 border rounded px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                                <button type="button"
                                        @click="addTaskComment()"
                                        class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 whitespace-nowrap">
                                    <i class="fas fa-paper-plane mr-1"></i>Send
                                </button>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Status</label>
                                <select x-model="pendingTaskStatus"
                                        class="w-full border rounded px-2 py-1 text-sm bg-gray-50">
                                    <option value="pending">To Do</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Done</option>
                                    <option value="review">Review</option>
                                    <option value="archived">Archive</option>
                                </select>
                                <button type="button"
                                        @click="promptStatusChange()"
                                        x-show="pendingTaskStatus !== currentTaskStatus"
                                        class="mt-1 w-full text-xs bg-blue-600 text-white rounded px-2 py-1 hover:bg-blue-700">
                                    Apply Status Change
                                </button>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase">Priority</label>
                                <div class="mt-1">
                                    <span :class="{
                                        'bg-red-100 text-red-800': currentTask?.priority === 'high',
                                        'bg-yellow-100 text-yellow-800': currentTask?.priority === 'medium',
                                        'bg-blue-100 text-blue-800': currentTask?.priority === 'low'
                                    }" class="px-2 py-1 rounded text-xs font-bold uppercase" x-text="currentTask?.priority"></span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase">Assigned To</label>
                                <p class="mt-1 text-sm font-medium" x-text="currentTask?.assigned_to_name"></p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase">Due Date</label>
                                <p class="mt-1 text-sm flex items-center"
                                   :class="isOverdue(currentTask?.due_date) ? 'text-red-600 font-bold' : 'text-gray-700'">
                                    <i class="far fa-calendar-alt mr-1"></i>
                                    <span x-text="currentTask?.due_date || 'No due date'"></span>
                                </p>
                            </div>
                            <!-- Action buttons -->
                            <div class="pt-2 border-t space-y-2">
                                <button type="button"
                                        @click="promptDeleteTask(currentTask)"
                                        class="w-full text-xs bg-red-50 text-red-600 border border-red-200 rounded px-3 py-2 hover:bg-red-100">
                                    <i class="fas fa-trash mr-1"></i>Delete Task
                                </button>
                                <button type="button"
                                        @click="showTaskDetail = false"
                                        class="w-full text-xs bg-gray-100 text-gray-600 rounded px-3 py-2 hover:bg-gray-200">
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Task Action Reason Modal (status change / delete / cancel) -->
            <div x-show="showTaskReasonModal" @click.outside="showTaskReasonModal = false" @keydown.escape.window="showTaskReasonModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60]" x-cloak>
                <div class="bg-white rounded-lg p-6 w-96 shadow-xl">
                    <h3 class="text-lg font-bold mb-1" x-text="taskReasonTitle"></h3>
                    <p class="text-sm text-gray-500 mb-4" x-text="taskReasonSubtitle"></p>
                    <textarea x-model="taskReasonText"
                              class="w-full border rounded px-3 py-2 text-sm mb-4 focus:outline-none focus:border-blue-500"
                              rows="3"
                              placeholder="Please enter a reason (minimum 3 words)..."></textarea>
                    <div class="flex justify-end space-x-2">
                        <button @click="showTaskReasonModal = false; taskReasonText = ''"
                                class="px-4 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200">Cancel</button>
                        <button @click="confirmTaskReasonAction()"
                                :class="taskReasonAction === 'delete' ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'"
                                class="px-4 py-2 text-white rounded font-medium"
                                x-text="taskReasonAction === 'delete' ? 'Delete' : 'Confirm'"></button>
                    </div>
                </div>
            </div>

            <!-- Task Creation Modal -->
            <div x-show="showTaskModal" @click.outside="showTaskModal = false" @keydown.escape.window="showTaskModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Create New Task</h3>
                    <form @submit.prevent="createTask">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Title</label>
                            <input type="text" x-model="taskForm.title" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Description</label>
                            <textarea x-model="taskForm.description" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Assign To</label>
                            <select x-model="taskForm.assigned_to" class="w-full border rounded px-3 py-2">
                                <option value="">Select User</option>
                                <template x-for="user in users" :key="user.id">
                                    <option :value="user.id" x-text="user.full_name"></option>
                                </template>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Priority</label>
                            <select x-model="taskForm.priority" class="w-full border rounded px-3 py-2">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Due Date</label>
                            <input type="date" x-model="taskForm.due_date" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showTaskModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Create</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Contact Creation Modal -->
            <div x-show="showContactModal" @click.outside="showContactModal = false" @keydown.escape.window="showContactModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Add Contact</h3>
                    <form @submit.prevent="addContact">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Name</label>
                            <input type="text" x-model="contactForm.name" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Profession</label>
                            <input type="text" x-model="contactForm.profession" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Contact Number</label>
                            <input type="text" x-model="contactForm.contact_number" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Locality</label>
                            <input type="text" x-model="contactForm.locality" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Notes</label>
                            <textarea x-model="contactForm.notes" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="flex justify-end space-x-2 mt-4">
                            <button @click="showContactModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Add</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Add/Edit User Modal -->
            <div x-show="showAddUserModal" @click.outside="showAddUserModal = false" @keydown.escape.window="showAddUserModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-11/12 md:w-3/4 lg:w-1/2 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4" x-text="editingUser ? 'Edit User' : 'Add New User'"></h3>
                    <form @submit.prevent="saveUser">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Full Name</label>
                                <input type="text" x-model="userForm.full_name" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Email</label>
                                <input type="email" x-model="userForm.email" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Phone</label>
                                <input type="tel" x-model="userForm.phone" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Role</label>
                                <select x-model="userForm.role" class="w-full border rounded px-3 py-2">
                                    <option value="staff">Staff</option>
                                    <option value="manager">Manager</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Department</label>
                                <input type="text" x-model="userForm.department" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Reporting Head</label>
                                <select x-model="userForm.reporting_head" class="w-full border rounded px-3 py-2">
                                    <option value="0">None</option>
                                    <template x-for="u in users.filter(u => u.id !== userForm.id)" :key="u.id">
                                        <option :value="u.id" x-text="u.full_name"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Status</label>
                                <select x-model="userForm.is_active" class="w-full border rounded px-3 py-2">
                                    <option :value="1">Active</option>
                                    <option :value="0">Inactive</option>
                                </select>
                            </div>
                            <div class="mb-2" x-show="!editingUser">
                                <label class="block text-sm font-medium mb-1">Password</label>
                                <input type="password" x-model="userForm.password" class="w-full border rounded px-3 py-2" :required="!editingUser">
                            </div>
                        </div>
                        <div class="flex justify-end space-x-2 mt-4">
                            <button @click="showAddUserModal = false; editingUser = null;" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Worker Creation/Edit Modal -->
            <div x-show="showAddWorker" @click.outside="showAddWorker = false" @keydown.escape.window="showAddWorker = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-11/12 md:w-3/4 lg:w-1/2 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4" x-text="editingWorker ? 'Edit Worker' : 'Add New Worker'"></h3>
                    <form @submit.prevent="saveWorker">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Basic Details -->
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Full Name</label>
                                <input type="text" x-model="workerForm.name" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Father/Husband Name</label>
                                <input type="text" x-model="workerForm.father_name" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Date of Birth</label>
                                <input type="date" x-model="workerForm.dob" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Gender</label>
                                <select x-model="workerForm.gender" class="w-full border rounded px-3 py-2">
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <!-- Contact Details -->
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Phone Number</label>
                                <input type="tel" x-model="workerForm.phone" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Email</label>
                                <input type="email" x-model="workerForm.email" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2 md:col-span-2">
                                <label class="block text-sm font-medium mb-1">Address</label>
                                <textarea x-model="workerForm.address" class="w-full border rounded px-3 py-2" rows="2"></textarea>
                            </div>

                            <!-- Professional Details -->
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Skills</label>
                                <input type="text" x-model="workerForm.skills" class="w-full border rounded px-3 py-2" placeholder="e.g., Electrician, Plumber">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Experience</label>
                                <input type="text" x-model="workerForm.experience" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Qualification</label>
                                <input type="text" x-model="workerForm.qualification" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Reporting Head</label>
                                <select x-model="workerForm.reporting_head" class="w-full border rounded px-3 py-2">
                                    <option value="0">Self / Top Level</option>
                                    <template x-for="user in users" :key="user.id">
                                        <option :value="user.id" x-text="user.full_name"></option>
                                    </template>
                                </select>
                            </div>

                            <!-- Other Details -->
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Blood Group</label>
                                <select x-model="workerForm.blood_group" class="w-full border rounded px-3 py-2">
                                    <option value="">Select</option>
                                    <option value="A+">A+</option>
                                    <option value="A-">A-</option>
                                    <option value="B+">B+</option>
                                    <option value="B-">B-</option>
                                    <option value="AB+">AB+</option>
                                    <option value="AB-">AB-</option>
                                    <option value="O+">O+</option>
                                    <option value="O-">O-</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Status</label>
                                <select x-model="workerForm.status" class="w-full border rounded px-3 py-2">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-2 mt-4">
                            <button @click="showAddWorker = false; editingWorker = null;" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function ops() {
                return {
                    activeTab: 'tasks',
                    tasks: {
                        todo: [],
                        progress: [],
                        done: [],
                        review: [],
                        archive: []
                    },
                    taskFilter: 'all',
                    allTasksList: [],
                    filteredTasksList: [],
                    currentTask: null,
                    showTaskDetail: false,
                    taskComments: [],
                    newTaskComment: '',
                    currentTaskStatus: '',
                    pendingTaskStatus: '',
                    showTaskReasonModal: false,
                    taskReasonAction: '',  // 'status_change' | 'delete'
                    taskReasonTitle: '',
                    taskReasonSubtitle: '',
                    taskReasonText: '',
                    draggedTask: null,
                    showTaskModal: false,
                    showContactModal: false,
                    showAddWorker: false,
                    showAddUserModal: false, // New state for user modal
                    workers: [],
                    users: [], // This will now hold all users for management
                    approvalsList: '',
                    showApprovalModal: false,
                    approvalAction: 'approve',   // 'approve' | 'reject'
                    approvalReason: '',
                    currentApprovalId: null,
                    isChangeApproval: false,
                    contactsList: '',
                    workersList: '',
                    teamMessages: [],
                    onlineMembers: '',
                    currentUserId: <?php echo $_SESSION['admin_id']; ?>,
                    activeChatTab: 'team',
                    chatReceiverId: '0',
                    chatSessionId: '',
                    teamMessage: '',
                    contactSearch: '',
                    workerSearch: '',
                    editingWorker: null, // To track if we are editing a worker
                    editingUser: null, // To track if we are editing a user
                    // Add these to the ops() function's return object
allGuestSessions: [],
selectedGuestSession: '',
staffList: [],
chatLastId: 0,

loadAllGuestSessions() {
    fetch('api/chat.php?type=all_guest_sessions')
        .then(res => res.json())
        .then(data => {
            this.allGuestSessions = data;
        })
        .catch(err => console.error('Error loading all sessions:', err));
},

loadStaffList() {
    fetch('api/users.php?list=1&role=staff,manager,admin')
        .then(res => res.json())
        .then(data => {
            this.staffList = data;
        })
        .catch(err => console.error('Error loading staff list:', err));
},

fetchGuestMessages(sessionId) {
    if (!sessionId) return;
    
    fetch(`api/chat.php?type=guest&session_id=${sessionId}`)
        .then(res => res.json())
        .then(data => {
            this.teamMessages = data;
            this.chatLastId = data.length > 0 ? data[data.length - 1].id : 0;
            this.$nextTick(() => {
                let container = this.$refs.chatMessages;
                if (container) container.scrollTop = container.scrollHeight;
            });
        })
        .catch(err => console.error('Error fetching guest messages:', err));
},

fetchMessages() {
    let url = `api/chat.php?type=${this.activeChatTab}`;
    if (this.activeChatTab === 'guest' && this.selectedGuestSession) {
        url += `&session_id=${this.selectedGuestSession}`;
    }
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            this.teamMessages = Array.isArray(data) ? data : [];
            this.$nextTick(() => {
                let container = this.$refs.chatMessages;
                if (container) container.scrollTop = container.scrollHeight;
            });
        })
        .catch(err => console.error('Error fetching messages:', err));
},

sendTeamMessage() {
    if (!this.teamMessage.trim()) return;
    
    if (this.activeChatTab === 'staff' && !this.chatReceiverId) {
        alert("Please select a staff member to message.");
        return;
    }
    
    if (this.activeChatTab === 'guest' && !this.selectedGuestSession) {
        alert("Please select a guest session.");
        return;
    }

    let tempId = 'temp_' + Date.now() + '_' + Math.random().toString(36);
    
    // Optimistic update
    let optimisticMsg = {
        id: tempId,
        message: this.teamMessage,
        sender_id: this.currentUserId,
        sender_type: 'admin',
        sender_name: 'You',
        time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
        is_temp: true
    };
    this.teamMessages.push(optimisticMsg);
    let messageText = this.teamMessage;
    this.teamMessage = '';

    fetch('api/chat.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            message: messageText,
            type: this.activeChatTab,
            receiver_id: this.chatReceiverId,
            session_id: this.selectedGuestSession,
            temp_id: tempId
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Remove optimistic message and fetch real ones
            this.teamMessages = this.teamMessages.filter(m => m.id !== tempId);
            this.fetchMessages();
            if (this.activeChatTab === 'guest') {
                this.loadAllGuestSessions();
            }
        } else {
            // Remove optimistic message on error
            this.teamMessages = this.teamMessages.filter(m => m.id !== tempId);
            alert(data.error || 'Failed to send message');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        this.teamMessages = this.teamMessages.filter(m => m.id !== tempId);
        alert('Network error');
    });
},

viewGuestDetails(session) {
    // Create modal with guest details
    let detailsHtml = `
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" id="guestDetailsModal">
            <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold">Guest Details</h3>
                    <button onclick="document.getElementById('guestDetailsModal').remove()" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="space-y-3">
                    <div><span class="font-medium">Name:</span> ${session.guest_name || 'Not provided'}</div>
                    <div><span class="font-medium">Email:</span> ${session.guest_email || 'Not provided'}</div>
                    <div><span class="font-medium">Phone:</span> ${session.guest_phone || 'Not provided'}</div>
                    <div><span class="font-medium">Reason:</span> ${session.contact_reason || 'Not provided'}</div>
                    <div><span class="font-medium">Device ID:</span> ${session.device_id || 'Not available'}</div>
                    <div><span class="font-medium">Started:</span> ${new Date(session.created_at).toLocaleString()}</div>
                    <div><span class="font-medium">Last Activity:</span> ${new Date(session.last_activity).toLocaleString()}</div>
                    <div><span class="font-medium">Status:</span> <span class="px-2 py-1 rounded-full text-xs ${session.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}">${session.status}</span></div>
                </div>
                <div class="mt-4 flex justify-end">
                    <button onclick="document.getElementById('guestDetailsModal').remove()" class="px-4 py-2 bg-gray-200 rounded">Close</button>
                </div>
            </div>
        </div>
    `;
    
    let tempDiv = document.createElement('div');
    tempDiv.innerHTML = detailsHtml;
    document.body.appendChild(tempDiv.firstChild);
},

terminateGuestSession(sessionId) {
    if (confirm('Terminate this guest chat? The guest will be notified.')) {
        fetch('api/chat.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ 
                action: 'terminate_session',
                session_id: sessionId 
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                this.loadAllGuestSessions();
                if (this.selectedGuestSession === sessionId) {
                    this.selectedGuestSession = '';
                    this.teamMessages = [];
                }
                window.appNotify('Chat terminated');
            }
        })
        .catch(err => console.error('Error:', err));
    }
}
                    
                    taskForm: {
                        title: '',
                        description: '',
                        assigned_to: '',
                        priority: 'medium',
                        due_date: ''
                    },
                    
                    contactForm: {
                        name: '',
                        profession: '',
                        contact_number: '',
                        locality: '',
                        notes: ''
                    },

                    workerForm: {
                        name: '',
                        father_name: '',
                        dob: '',
                        gender: '',
                        phone: '',
                        email: '',
                        address: '',
                        skills: '',
                        experience: '',
                        qualification: '',
                        status: 'active'
                    },

                    userForm: { // New form for user management
                        id: null,
                        full_name: '',
                        email: '',
                        phone: '',
                        role: 'staff',
                        department: '',
                        reporting_head: '0',
                        is_active: 1,
                        password: ''
                    },

                    init() {
                        this.loadTasks();
                        this.loadApprovals();
                        this.loadDirectory();
                        this.loadWorkers();
                        this.loadUsers(); // Load users for both task assignment and user management
                        this.fetchMessages();
                        this.loadOnlineMembers();
                        
                        this.$watch('activeTab', (value) => {
                            if (value === 'communicator') {
                                this.fetchMessages();
                                this.loadOnlineMembers();
                            }
                            if (value === 'users') {
                                this.loadUsers();
                            }
                            if (value === 'approvals') {
                                this.loadApprovals(); // re-fetch & re-register bridges
                            }
                        });

                        // Also watch approvalsList: re-register bridges whenever content updates
                        this.$watch('approvalsList', () => {
                            this.$nextTick(() => this._registerApprovalBridges());
                        });

                        // Re-apply filter whenever taskFilter changes
                        this.$watch('taskFilter', () => this._applyTaskFilter());
                    },

                    loadTasks() {
                        fetch('api/tasks.php')
                            .then(res => res.json())
                            .then(data => {
                                this.tasks = data;
                                this.allTasksList = [
                                    ...(data.todo      || []),
                                    ...(data.progress  || []),
                                    ...(data.done      || []),
                                    ...(data.review    || []),
                                    ...(data.archive   || [])
                                ];
                                this._applyTaskFilter();
                            })
                            .catch(() => {});
                    },

                    _applyTaskFilter() {
                        if (this.taskFilter === 'all') {
                            // "All Active" excludes archived
                            this.filteredTasksList = this.allTasksList.filter(t => t.status !== 'archived');
                        } else {
                            this.filteredTasksList = this.allTasksList.filter(t => t.status === this.taskFilter);
                        }
                    },

                    viewTask(task) {
                        this.currentTask       = task;
                        this.currentTaskStatus = task.status;
                        this.pendingTaskStatus = task.status;
                        this.showTaskDetail    = true;
                        this.newTaskComment    = '';
                        this.loadTaskComments(task.id);
                    },

                    loadTaskComments(taskId) {
                        fetch(`api/tasks.php?comments=${taskId}`)
                            .then(res => res.json())
                            .then(data => {
                                this.taskComments = Array.isArray(data) ? data : [];
                            })
                            .catch(() => { this.taskComments = []; });
                    },

                    addTaskComment() {
                        const text = (this.newTaskComment || '').trim();
                        if (!text) return;
                        if (!this.currentTask) return;
                        fetch('api/tasks.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                action:   'comment',
                                task_id:  this.currentTask.id,
                                comment:  text
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.newTaskComment = '';
                                this.loadTaskComments(this.currentTask.id);
                            } else {
                                alert(data.error || 'Failed to add comment');
                            }
                        })
                        .catch(() => alert('Network error'));
                    },

                    promptStatusChange() {
                        const labels = {pending:'To Do',in_progress:'In Progress',completed:'Done',review:'Review',archived:'Archived'};
                        this.taskReasonAction   = 'status_change';
                        this.taskReasonTitle    = 'Change Task Status';
                        this.taskReasonSubtitle = `Changing from "${labels[this.currentTaskStatus]}" to "${labels[this.pendingTaskStatus]}". Please provide a reason.`;
                        this.taskReasonText     = '';
                        this.showTaskReasonModal = true;
                    },

                    promptDeleteTask(task) {
                        this.currentTask        = task;
                        this.taskReasonAction   = 'delete';
                        this.taskReasonTitle    = 'Delete Task';
                        this.taskReasonSubtitle = `You are about to permanently delete "${task.title}". This cannot be undone.`;
                        this.taskReasonText     = '';
                        this.showTaskReasonModal = true;
                    },

                    confirmTaskReasonAction() {
                        const words = this.taskReasonText.trim().split(/\s+/).filter(Boolean);
                        if (words.length < 3) {
                            alert('Please provide at least 3 words as a reason.');
                            return;
                        }
                        if (this.taskReasonAction === 'delete') {
                            fetch('api/tasks.php', {
                                method: 'DELETE',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ id: this.currentTask.id, reason: this.taskReasonText })
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.showTaskReasonModal = false;
                                    this.showTaskDetail = false;
                                    this.taskReasonText = '';
                                    this.loadTasks();
                                    window.appNotify('Task deleted');
                                } else {
                                    alert(data.error || 'Failed to delete task');
                                }
                            })
                            .catch(() => alert('Network error'));
                        } else if (this.taskReasonAction === 'status_change') {
                            fetch('api/tasks.php', {
                                method: 'PUT',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    id:     this.currentTask.id,
                                    status: this.pendingTaskStatus,
                                    reason: this.taskReasonText
                                })
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.showTaskReasonModal = false;
                                    this.taskReasonText = '';
                                    this.currentTaskStatus = this.pendingTaskStatus;
                                    this.currentTask.status = this.pendingTaskStatus;
                                    this.loadTasks();
                                    window.appNotify('Task status updated');
                                } else {
                                    alert(data.error || 'Failed to update status');
                                }
                            })
                            .catch(() => alert('Network error'));
                        }
                    },

                    isOverdue(dateStr) {
                        if (!dateStr) return false;
                        const d = new Date(dateStr);
                        const now = new Date();
                        return d < now && this.currentTaskStatus !== 'completed' && this.currentTaskStatus !== 'archived';
                    },

                    loadApprovals() {
                        fetch('api/approvals.php')
                            .then(res => res.text())
                            .then(data => {
                                this.approvalsList = data;
                                // Register window bridges AFTER html is injected
                                this.$nextTick(() => this._registerApprovalBridges());
                            })
                            .catch(() => { this.approvalsList = '<tr><td colspan="5" class="text-center py-6 text-gray-400">Failed to load approvals.</td></tr>'; });
                    },

                    // Called after x-html injects the approval rows, bridges window.opsApprove/opsReject
                    // so onclick= attributes in the injected HTML can reach Alpine component scope
                    _registerApprovalBridges() {
                        const self = this;
                        window.opsApprove = function(id, isChange) {
                            self.currentApprovalId  = id;
                            self.isChangeApproval   = !!isChange;
                            self.approvalAction     = 'approve';
                            self.approvalReason     = '';
                            self.showApprovalModal  = true;
                        };
                        window.opsReject = function(id, isChange) {
                            self.currentApprovalId  = id;
                            self.isChangeApproval   = !!isChange;
                            self.approvalAction     = 'reject';
                            self.approvalReason     = '';
                            self.showApprovalModal  = true;
                        };
                    },

                    submitApprovalAction() {
                        if (this.approvalAction === 'reject') {
                            const words = this.approvalReason.trim().split(/\s+/).filter(Boolean);
                            if (words.length < 2) {
                                alert('Please provide at least 2 words for the rejection reason.');
                                return;
                            }
                        }
                        const apiAction = this.approvalAction === 'approve'
                            ? (this.isChangeApproval ? 'approve_change' : 'approve')
                            : (this.isChangeApproval ? 'reject_change' : 'reject');

                        const notifyMsg = this.approvalAction === 'approve'
                            ? 'Request approved successfully'
                            : 'Request rejected';

                        fetch('api/approvals.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                action: apiAction,
                                id: this.currentApprovalId,
                                reason: this.approvalReason
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showApprovalModal = false;
                                this.approvalReason   = '';
                                this.currentApprovalId = null;
                                this.loadApprovals();
                                window.appNotify(notifyMsg);
                            } else {
                                alert(data.error || 'Action failed. Please try again.');
                            }
                        })
                        .catch(() => alert('Network error. Please try again.'));
                    },

                    loadDirectory() {
                        this.searchContacts();
                        this.searchWorkers();
                    },

                    searchContacts() {
                        fetch(`api/directory.php?search=${this.contactSearch}&type=contacts`)
                            .then(res => res.text())
                            .then(data => {
                                this.contactsList = data;
                            });
                    },

                    searchWorkers() {
                        fetch(`api/directory.php?search=${this.workerSearch}&type=workers`)
                            .then(res => res.text())
                            .then(data => {
                                this.workersList = data;
                            });
                    },

                    loadWorkers() {
                        fetch('api/workers.php')
                            .then(res => res.json())
                            .then(data => {
                                this.workers = data;
                                this.$nextTick(() => {
                                    this.workers.forEach(worker => {
                                        this.generateQR(worker);
                                    });
                                });
                            });
                    },

                    loadUsers() {
                        fetch('api/users.php?list=1')
                            .then(res => res.json())
                            .then(data => {
                                this.users = data;
                            });
                    },

                    loadOnlineMembers() {
                        fetch('api/online.php')
                            .then(res => res.text())
                            .then(data => {
                                this.onlineMembers = data;
                            });
                    },

                    scrollToBottom() {
                        this.$nextTick(() => {
                            let container = this.$refs.chatMessages;
                            if (container) container.scrollTop = container.scrollHeight;
                        });
                    },

                    fetchMessages() {
                        let url = `api/chat.php?type=${this.activeChatTab}`;
                        if (this.activeChatTab === 'guest' && this.chatSessionId) {
                            url += `&session_id=${this.chatSessionId}`;
                        }
                        fetch(url)
                            .then(res => res.json())
                            .then(data => {
                                if (!data.error) {
                                    this.teamMessages = data;
                                    setTimeout(() => {
                                        if(this.$refs.chatMessages) {
                                            this.$refs.chatMessages.scrollTop = this.$refs.chatMessages.scrollHeight;
                                        }
                                    }, 100);
                                }
                            })
                            .catch(err => console.error("Error fetching messages:", err));
                    },

                    sendTeamMessage() {
                        if (!this.teamMessage.trim()) return;
                        
                        if (this.activeChatTab === 'staff' && this.chatReceiverId === '0') {
                            alert("Please select a staff member to message.");
                            return;
                        }

                        fetch('api/chat.php', {
                            method: 'POST',
                            body: JSON.stringify({
                                message: this.teamMessage,
                                type: this.activeChatTab,
                                receiver_id: this.chatReceiverId,
                                session_id: this.chatSessionId
                            }),
                            headers: {'Content-Type': 'application/json'}
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.teamMessage = '';
                                this.fetchMessages();
                            } else {
                                alert(data.error || 'Failed to send message');
                            }
                        });
                    },

                    dragStart(event, task) {
                        this.draggedTask = task;
                        event.dataTransfer.effectAllowed = 'move';
                    },

                    drop(event, status) {
                        event.preventDefault();
                        if (this.draggedTask) {
                            fetch('api/tasks.php', {
                                method: 'PUT',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    id: this.draggedTask.id,
                                    status: status
                                })
                            })
                            .then(() => {
                                this.loadTasks();
                                this.draggedTask = null;
                            });
                        }
                    },

                    createTask() {
                        fetch('api/tasks.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.taskForm)
                        })
                        .then(res => res.json())
                        .then(() => {
                            this.showTaskModal = false;
                            this.taskForm = {
                                title: '',
                                description: '',
                                assigned_to: '',
                                priority: 'medium',
                                due_date: ''
                            };
                            this.loadTasks();
                            window.appNotify('Task created successfully');
                        });
                    },

                    addContact() {
                        fetch('api/directory.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.contactForm)
                        })
                        .then(res => res.json())
                        .then(() => {
                            this.showContactModal = false;
                            this.contactForm = {
                                name: '',
                                profession: '',
                                contact_number: '',
                                locality: '',
                                notes: ''
                            };
                            this.searchContacts();
                            window.appNotify('Contact added successfully');
                        });
                    },

                                        generateQR(worker) {
                        const year = new Date().getFullYear().toString().slice(-2);
                        const month = new Date().toLocaleString('default', { month: 'short' }).toUpperCase();
                        const hexCode = worker.id.toString(16).toUpperCase().padStart(3, '0');
                        const workerCode = `DKW${year}${month}${hexCode}`;
                        
                        const qrData = JSON.stringify({
                            id: worker.id,
                            worker_code: workerCode,
                            name: worker.name,
                            skills: worker.skills,
                            type: 'worker'
                        });

                        const canvas = document.getElementById('qr-' + worker.id);
                        if (canvas) {
                            QRCode.toCanvas(canvas, qrData, {
                                width: 100,
                                margin: 1,
                                color: {
                                    dark: '#0f3b5e',
                                    light: '#ffffff'
                                }
                            }, function(error) {
                                if (error) console.error('QR Error:', error);
                            });
                        }
                    },

                                        viewWorker(worker) {
                        // Create modal to view worker details
                        const modalHtml = `
                            <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" id="viewWorkerModal">
                                <div class="bg-white rounded-lg p-6 w-2/3 max-h-screen overflow-y-auto">
                                    <div class="flex justify-between items-center mb-4">
                                        <h3 class="text-xl font-bold">Worker Details</h3>
                                        <button onclick="document.getElementById('viewWorkerModal').remove()" class="text-gray-500 hover:text-gray-700">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="grid grid-cols-3 gap-4">
                                        <!-- Photo Column -->
                                        <div class="col-span-1">
                                            <div class="bg-gray-100 rounded-lg p-4 text-center">
                                                <img src="${worker.photo_url || 'https://via.placeholder.com/150'}" 
                                                     class="w-32 h-32 rounded-full mx-auto mb-3 object-cover">
                                                <h4 class="font-bold text-lg">${worker.name}</h4>
                                                <p class="text-gray-600">${worker.skills || 'No skills listed'}</p>
                                                <span class="inline-block px-3 py-1 rounded-full text-sm mt-2 ${
                                                    worker.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                                                }">
                                                    ${worker.status}
                                                </span>
                                            </div>
                                            
                                            <div class="mt-4 bg-gray-50 rounded-lg p-4">
                                                <h5 class="font-bold mb-2">Quick Actions</h5>
                                                <div class="space-y-2">
                                                    <button onclick="document.getElementById('viewWorkerModal').remove(); window.workersData.editWorker(${JSON.stringify(worker).replace(/"/g, '&quot;')})" 
                                                            class="w-full text-left px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                                                        <i class="fas fa-edit mr-2"></i>Edit Worker
                                                    </button>
                                                    <button onclick="window.workersData.generateIDCard(${JSON.stringify(worker).replace(/"/g, '&quot;')})" 
                                                            class="w-full text-left px-3 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                                                        <i class="fas fa-id-card mr-2"></i>Generate ID Card
                                                    </button>
                                                    <button onclick="window.workersData.viewWorkerAttendance(${worker.id})" 
                                                            class="w-full text-left px-3 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">
                                                        <i class="fas fa-calendar-check mr-2"></i>View Attendance
                                                    </button>
                                                    <button onclick="window.workersData.viewWorkerDocuments(${worker.id})" 
                                                            class="w-full text-left px-3 py-2 bg-yellow-600 text-white rounded hover:bg-yellow-700">
                                                        <i class="fas fa-file mr-2"></i>View Documents
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Details Column -->
                                        <div class="col-span-2">
                                            <div class="bg-white border rounded-lg p-4">
                                                <h5 class="font-bold mb-3">Personal Information</h5>
                                                <div class="grid grid-cols-2 gap-4">
                                                    <div>
                                                        <p class="text-sm text-gray-500">Full Name</p>
                                                        <p class="font-medium">${worker.name}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Father's Name</p>
                                                        <p class="font-medium">${worker.father_name || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Date of Birth</p>
                                                        <p class="font-medium">${worker.dob || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Gender</p>
                                                        <p class="font-medium">${worker.gender || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Blood Group</p>
                                                        <p class="font-medium">${worker.blood_group || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Worker Code</p>
                                                        <p class="font-medium">${worker.worker_code || this.generateWorkerCode(worker.id)}</p>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="bg-white border rounded-lg p-4 mt-4">
                                                <h5 class="font-bold mb-3">Contact Information</h5>
                                                <div class="grid grid-cols-2 gap-4">
                                                    <div>
                                                        <p class="text-sm text-gray-500">Phone</p>
                                                        <p class="font-medium">${worker.phone || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Email</p>
                                                        <p class="font-medium">${worker.email || 'Not provided'}</p>
                                                    </div>
                                                    <div class="col-span-2">
                                                        <p class="text-sm text-gray-500">Address</p>
                                                        <p class="font-medium">${worker.address || 'Not provided'}</p>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="bg-white border rounded-lg p-4 mt-4">
                                                <h5 class="font-bold mb-3">Professional Information</h5>
                                                <div class="grid grid-cols-2 gap-4">
                                                    <div>
                                                        <p class="text-sm text-gray-500">Skills</p>
                                                        <p class="font-medium">${worker.skills || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Experience</p>
                                                        <p class="font-medium">${worker.experience || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Qualification</p>
                                                        <p class="font-medium">${worker.qualification || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Supervisor</p>
                                                        <p class="font-medium">${worker.supervisor || 'Not assigned'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Rating</p>
                                                        <p class="font-medium">${worker.rating || '0'} / 5</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Reporting Head</p>
                                                        <p class="font-medium">${worker.reporting_head_name || 'Not assigned'}</p>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="bg-white border rounded-lg p-4 mt-4">
                                                <h5 class="font-bold mb-3">Bank Details</h5>
                                                <pre class="text-sm bg-gray-50 p-3 rounded">${worker.bank_details ? JSON.stringify(JSON.parse(worker.bank_details), null, 2) : 'No bank details provided'}</pre>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        // Create a temporary div and append to body
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = modalHtml;
                        document.body.appendChild(tempDiv.firstChild);
                    },

                    editWorker(worker) {
                        // Populate form with worker data for editing
                        this.editingWorker = worker;
                        this.workerForm = {
                            name: worker.name || '',
                            father_name: worker.father_name || '',
                            dob: worker.dob || '',
                            gender: worker.gender || '',
                            phone: worker.phone || '',
                            email: worker.email || '',
                            address: worker.address || '',
                            skills: worker.skills || '',
                            experience: worker.experience || '',
                            qualification: worker.qualification || '',
                            blood_group: worker.blood_group || '',
                            supervisor: worker.supervisor || '',
                            status: worker.status || 'active',
                            photo_url: worker.photo_url || '',
                            worker_code: worker.worker_code || ''
                        };
                        this.showAddWorker = true;
                    },

                    viewWorkerAttendance(workerId) {
                        fetch(`api/attendance.php?worker_id=${workerId}`)
                            .then(res => res.json())
                            .then(data => {
                                const modalHtml = `
                                    <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" id="attendanceModal">
                                        <div class="bg-white rounded-lg p-6 w-2/3 max-h-screen overflow-y-auto">
                                            <div class="flex justify-between items-center mb-4">
                                                <h3 class="text-xl font-bold">Worker Attendance</h3>
                                                <button onclick="document.getElementById('attendanceModal').remove()" class="text-gray-500 hover:text-gray-700">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                            <div class="grid grid-cols-4 gap-4 mb-4">
                                                <div class="bg-green-100 p-3 rounded text-center">
                                                    <p class="text-2xl font-bold text-green-600">${data.present || 0}</p>
                                                    <p class="text-sm">Present</p>
                                                </div>
                                                <div class="bg-yellow-100 p-3 rounded text-center">
                                                    <p class="text-2xl font-bold text-yellow-600">${data.absent || 0}</p>
                                                    <p class="text-sm">Absent</p>
                                                </div>
                                                <div class="bg-blue-100 p-3 rounded text-center">
                                                    <p class="text-2xl font-bold text-blue-600">${data.leave || 0}</p>
                                                    <p class="text-sm">Leave</p>
                                                </div>
                                                <div class="bg-purple-100 p-3 rounded text-center">
                                                    <p class="text-2xl font-bold text-purple-600">${data.holiday || 0}</p>
                                                    <p class="text-sm">Holiday</p>
                                                </div>
                                            </div>
                                            <table class="w-full">
                                                <thead class="bg-gray-100">
                                                    <tr>
                                                        <th class="p-2 text-left">Date</th>
                                                        <th class="p-2 text-left">Status</th>
                                                        <th class="p-2 text-left">Check In</th>
                                                        <th class="p-2 text-left">Check Out</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${data.records.map(record => `
                                                        <tr class="border-b">
                                                            <td class="p-2">${record.date}</td>
                                                            <td class="p-2">
                                                                <span class="px-2 py-1 rounded-full text-xs ${
                                                                    record.status === 'present' ? 'bg-green-100 text-green-800' :
                                                                    record.status === 'absent' ? 'bg-red-100 text-red-800' :
                                                                    'bg-yellow-100 text-yellow-800'
                                                                }">${record.status}</span>
                                                            </td>
                                                            <td class="p-2">${record.punch_in || '-'}</td>
                                                            <td class="p-2">${record.punch_out || '-'}</td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                `;
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = modalHtml;
                                document.body.appendChild(tempDiv.firstChild);
                            });
                    },

                    viewWorkerDocuments(workerId) {
                        fetch(`api/workers.php?documents=${workerId}`)
                            .then(res => res.json())
                            .then(data => {
                                const modalHtml = `
                                    <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" id="documentsModal">
                                        <div class="bg-white rounded-lg p-6 w-2/3">
                                            <div class="flex justify-between items-center mb-4">
                                                <h3 class="text-xl font-bold">Worker Documents</h3>
                                                <button onclick="document.getElementById('documentsModal').remove()" class="text-gray-500 hover:text-gray-700">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                            <div class="grid grid-cols-3 gap-4">
                                                ${data.documents ? JSON.parse(data.documents).map(doc => `
                                                    <div class="border rounded p-3 text-center">
                                                        <i class="fas fa-file-pdf text-4xl text-red-600 mb-2"></i>
                                                        <p class="text-sm font-medium">${doc.name}</p>
                                                        <p class="text-xs text-gray-500">${doc.type}</p>
                                                        <a href="${doc.url}" target="_blank" class="text-blue-600 text-sm mt-2 inline-block">View</a>
                                                    </div>
                                                `).join('') : '<p class="col-span-3 text-center text-gray-500">No documents uploaded</p>'}
                                            </div>
                                            <div class="mt-4">
                                                <button class="bg-blue-600 text-white px-4 py-2 rounded" onclick="document.getElementById('documentsModal').remove()">
                                                    Close
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                `;
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = modalHtml;
                                document.body.appendChild(tempDiv.firstChild);
                            });
                    },

                    generateWorkerCode(id) {
                        const year = new Date().getFullYear().toString().slice(-2);
                        const month = new Date().toLocaleString('default', { month: 'short' }).toUpperCase();
                        const hexCode = id.toString(16).toUpperCase().padStart(3, '0');
                        return `DKW${year}${month}${hexCode}`;
                    },

                    saveWorker() {
                        // Validate required fields
                        if (!this.workerForm.name || !this.workerForm.phone) {
                            alert('Name and Phone are required fields');
                            return;
                        }

                        // Generate worker code if not exists
                        if (!this.workerForm.worker_code) {
                            const nextId = this.workers.length > 0 ? Math.max(...this.workers.map(w => w.id)) + 1 : 1;
                            this.workerForm.worker_code = this.generateWorkerCode(nextId);
                        }

                        const url = this.editingWorker ? `api/workers.php?id=${this.editingWorker.id}` : 'api/workers.php';
                        const method = this.editingWorker ? 'PUT' : 'POST';

                        fetch(url, {
                            method: method,
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.workerForm)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showAddWorker = false;
                                this.loadWorkers();
                                this.editingWorker = null;
                                this.workerForm = {
                                    name: '',
                                    father_name: '',
                                    dob: '',
                                    gender: '',
                                    phone: '',
                                    email: '',
                                    address: '',
                                    skills: '',
                                    experience: '',
                                    qualification: '',
                                    blood_group: '',
                                    supervisor: '',
                                    status: 'active'
                                };
                                window.appNotify(
                                    this.editingWorker ? 'Worker updated successfully' : 'Worker added successfully'
                                );
                            } else {
                                alert(data.error || 'Failed to save worker');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while saving worker');
                        });
                    },

                                        generateIDCard(worker) {
                        const year = new Date().getFullYear().toString().slice(-2);
                        const month = new Date().toLocaleString('default', { month: 'short' }).toUpperCase();
                        const hexCode = worker.id.toString(16).toUpperCase().padStart(3, '0');
                        const workerCode = `DKW${year}${month}${hexCode}`;
                        
                        // Get company logo from settings
                        const logo = this.$root.settings?.site_logo || '🏢';
                        const logoHtml = logo.startsWith('http') ? 
                            `<img src="${logo}" style="max-width: 100%; max-height: 100%;">` : 
                            `<span style="font-size: 24px;">${logo}</span>`;
                        
                        // Create temporary container for ID card
                        const tempDiv = document.createElement('div');
                        tempDiv.style.position = 'absolute';
                        tempDiv.style.left = '-9999px';
                        tempDiv.style.top = '-9999px';
                        tempDiv.innerHTML = `
                            <div class="id-card-preview" style="width: 85.6mm; height: 53.98mm; background: white; border: 1px solid #ccc; border-radius: 3mm; padding: 5mm; position: relative; font-family: Arial, sans-serif; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                                <div class="logo" style="position: absolute; top: 5mm; left: 5mm; width: 15mm; height: 15mm; display: flex; align-items: center; justify-content: center;">
                                    ${logoHtml}
                                </div>
                                <img src="${worker.photo_url || 'https://via.placeholder.com/80x80?text=Photo'}" 
                                     style="position: absolute; top: 5mm; right: 5mm; width: 20mm; height: 20mm; border-radius: 2mm; object-fit: cover; border: 1px solid #ddd;">
                                <div style="position: absolute; top: 5mm; left: 25mm; right: 30mm;">
                                    <div style="font-weight: bold; font-size: 12pt; margin-bottom: 2px;">${worker.name}</div>
                                    <div style="font-size: 10pt; color: #666; margin-bottom: 2px;">${worker.skills || 'Worker'}</div>
                                    <div style="font-size: 8pt; color: #999; margin-bottom: 2px;">ID: ${workerCode}</div>
                                    <div style="font-size: 8pt; color: #999; margin-bottom: 2px;">Supervisor: ${worker.supervisor || 'Not Assigned'}</div>
                                    ${worker.blood_group ? `<div style="font-size: 8pt; color: #999;">Blood: ${worker.blood_group}</div>` : ''}
                                </div>
                                <div class="qr" style="position: absolute; bottom: 5mm; right: 5mm; width: 15mm; height: 15mm;" id="temp-qr-${worker.id}"></div>
                                <div class="footer" style="position: absolute; bottom: 5mm; left: 5mm; font-size: 6pt; color: #999;">
                                    www.hidk.in | Valid ID
                                </div>
                            </div>
                        `;
                        
                        document.body.appendChild(tempDiv);
                        
                        // Generate QR in the temporary element
                        const qrData = JSON.stringify({
                            id: worker.id,
                            worker_code: workerCode,
                            name: worker.name,
                            type: 'worker'
                        });
                        
                        const tempCanvas = document.createElement('canvas');
                        QRCode.toCanvas(tempCanvas, qrData, { width: 50, margin: 0 }, function(error) {
                            if (error) {
                                console.error('QR Error:', error);
                                document.body.removeChild(tempDiv);
                                return;
                            }
                            
                            // Replace QR placeholder with canvas
                            const qrPlaceholder = document.getElementById(`temp-qr-${worker.id}`);
                            if (qrPlaceholder) {
                                tempCanvas.style.width = '100%';
                                tempCanvas.style.height = '100%';
                                qrPlaceholder.appendChild(tempCanvas);
                            }
                            
                            // Capture and download
                            html2canvas(tempDiv.firstChild, {
                                scale: 2,
                                backgroundColor: '#ffffff'
                            }).then(canvas => {
                                const link = document.createElement('a');
                                link.download = `Worker_ID_${workerCode}.png`;
                                link.href = canvas.toDataURL('image/png');
                                link.click();
                                
                                // Clean up
                                document.body.removeChild(tempDiv);
                            });
                        });
                    },

                    // User Management Methods
                    openAddUserModal() {
                        this.editingUser = null;
                        this.userForm = {
                            id: null,
                            full_name: '',
                            email: '',
                            phone: '',
                            role: 'staff',
                            department: '',
                            reporting_head: '0',
                            is_active: 1,
                            password: ''
                        };
                        this.showAddUserModal = true;
                    },

                    editUser(user) {
                        this.editingUser = user;
                        this.userForm = { ...user, is_active: user.is_active ? 1 : 0, password: '' }; // Ensure is_active is number
                        this.showAddUserModal = true;
                    },

                    saveUser() {
                        if (!this.userForm.full_name || !this.userForm.email || !this.userForm.role) {
                            alert('Full Name, Email, and Role are required.');
                            return;
                        }
                        if (!this.editingUser && !this.userForm.password) {
                            alert('Password is required for new users.');
                            return;
                        }

                        const url = this.editingUser ? `api/users.php?id=${this.editingUser.id}` : 'api/users.php';
                        const method = this.editingUser ? 'PUT' : 'POST';

                        fetch(url, {
                            method: method,
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.userForm)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showAddUserModal = false;
                                this.loadUsers();
                                window.appNotify(
                                    this.editingUser ? 'User updated successfully' : 'User added successfully'
                                );
                            } else {
                                alert(data.error || 'Failed to save user');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while saving user');
                        });
                    },

                    deleteUser(id) {
                        if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                            fetch(`api/users.php?id=${id}`, {
                                method: 'DELETE'
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.loadUsers();
                                    window.appNotify('User deleted successfully');
                                } else {
                                    alert(data.error || 'Failed to delete user');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('An error occurred while deleting user');
                            });
                        }
                    },

                    toggleUser(user) {
                        const newStatus = user.is_active ? 0 : 1;
                        if (confirm(`Are you sure you want to ${newStatus ? 'activate' : 'deactivate'} ${user.full_name}?`)) {
                            fetch(`api/users.php?id=${user.id}`, {
                                method: 'PUT',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ is_active: newStatus })
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.loadUsers();
                                    window.appNotify(`User ${newStatus ? 'activated' : 'deactivated'}`);
                                } else {
                                    alert(data.error || 'Failed to change user status');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('An error occurred while changing user status');
                            });
                        }
                    },

                    getReportingHeadName(id) {
                        if (id === '0' || !id) return 'None';
                        const head = this.users.find(u => u.id == id);
                        return head ? head.full_name : 'Unknown';
                    },
                }
            }
        </script>
        <?php
    }

    private function renderManagement() {
        ?>
        <div x-data="management()" x-init="init()" class="overflow-x-hidden">
            <h1 class="text-3xl font-bold mb-6">Pipeline & Project Management</h1>

            <!-- Recruitment Pipeline -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold">Recruitment Pipeline</h2>
                    <button @click="showVacancyModal = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>Add Vacancy
                    </button>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-5 gap-4">
                        <div class="bg-gray-100 rounded p-3">
                            <h3 class="font-bold text-sm mb-2">Applications</h3>
                            <div class="space-y-2" x-html="recruitment.applications"></div>
                        </div>
                        <div class="bg-gray-100 rounded p-3">
                            <h3 class="font-bold text-sm mb-2">Screening</h3>
                            <div class="space-y-2" x-html="recruitment.screening"></div>
                        </div>
                        <div class="bg-gray-100 rounded p-3">
                            <h3 class="font-bold text-sm mb-2">Interviews</h3>
                            <div class="space-y-2" x-html="recruitment.interviews"></div>
                        </div>
                        <div class="bg-gray-100 rounded p-3">
                            <h3 class="font-bold text-sm mb-2">Offers</h3>
                            <div class="space-y-2" x-html="recruitment.offers"></div>
                        </div>
                        <div class="bg-gray-100 rounded p-3">
                            <h3 class="font-bold text-sm mb-2">Onboarding</h3>
                            <div class="space-y-2" x-html="recruitment.onboarding"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Daily Reports -->
            <div class="bg-white rounded-lg shadow mb-6" x-data="dailyReports()" x-init="init()">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold"><i class="fas fa-clipboard-list mr-2 text-blue-600"></i>Daily Reports</h2>
                    <button @click="showSubmitModal = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>Submit My Report
                    </button>
                </div>
                <div class="p-4">
                    <div class="flex gap-4 mb-4">
                        <input type="date" x-model="filterDate" @change="loadReports()" class="border rounded px-3 py-2">
                        <select x-model="filterUser" @change="loadReports()" class="border rounded px-3 py-2">
                            <option value="">All Members</option>
                            <template x-for="u in teamList" :key="u.id">
                                <option :value="u.id" x-text="u.full_name"></option>
                            </template>
                        </select>
                    </div>
                    <div class="space-y-3">
                        <template x-for="r in reports" :key="r.id">
                            <div class="border rounded-lg p-4">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <span class="font-semibold" x-text="r.full_name"></span>
                                        <span class="text-gray-400 text-sm ml-2" x-text="r.report_date"></span>
                                        <span class="ml-2 text-xs px-2 py-1 rounded-full" 
                                            :class="{'bg-green-100 text-green-800': r.role === 'admin', 'bg-blue-100 text-blue-800': r.role === 'manager', 'bg-gray-100 text-gray-700': !['admin','manager'].includes(r.role)}"
                                            x-text="r.role"></span>
                                    </div>
                                    <div class="text-yellow-500">★★★★★</div>
                                </div>
                                <p class="mt-2 text-gray-700 text-sm" x-text="r.content"></p>
                                <div x-show="r.tasks_completed" class="mt-2 text-sm text-gray-500">
                                    <strong>Completed:</strong> <span x-text="r.tasks_completed"></span>
                                </div>
                                <div x-show="r.blockers" class="mt-1 text-sm text-red-600">
                                    <strong>Blockers:</strong> <span x-text="r.blockers"></span>
                                </div>
                            </div>
                        </template>
                        <div x-show="reports.length === 0" class="text-center text-gray-400 py-8">No reports found for selected filters.</div>
                    </div>
                </div>

                <!-- Submit Report Modal -->
                <div x-show="showSubmitModal" @click.outside="showSubmitModal = false" @keydown.escape.window="showSubmitModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white rounded-lg p-6 w-[560px] max-h-[90vh] overflow-y-auto">
                        <h3 class="text-lg font-bold mb-4">Submit Daily Report</h3>
                        <form @submit.prevent="submitReport">
                            <div class="mb-3">
                                <label class="block text-sm font-medium mb-1">Date</label>
                                <input type="date" x-model="reportForm.report_date" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-3">
                                <label class="block text-sm font-medium mb-1">What did you accomplish today?</label>
                                <textarea x-model="reportForm.content" class="w-full border rounded px-3 py-2" rows="4" required placeholder="Describe your work today..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="block text-sm font-medium mb-1">Tasks Completed</label>
                                <input type="text" x-model="reportForm.tasks_completed" class="w-full border rounded px-3 py-2" placeholder="List completed tasks...">
                            </div>
                            <div class="mb-3">
                                <label class="block text-sm font-medium mb-1">Blockers / Issues</label>
                                <input type="text" x-model="reportForm.blockers" class="w-full border rounded px-3 py-2" placeholder="Any blockers?">
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="showSubmitModal = false" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Submit Report</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <script>
            function dailyReports() {
                return {
                    reports: [],
                    teamList: [],
                    filterDate: new Date().toISOString().split('T')[0],
                    filterUser: '',
                    showSubmitModal: false,
                    reportForm: {
                        report_date: new Date().toISOString().split('T')[0],
                        content: '',
                        tasks_completed: '',
                        blockers: '',
                        mood: 3
                    },
                    init() {
                        this.loadReports();
                        fetch('api/users.php?list=1').then(r => r.json()).then(d => { this.teamList = d; }).catch(() => {});
                    },
                    loadReports() {
                        let url = `api/reports.php?date=${this.filterDate}`;
                        if (this.filterUser) url += `&user_id=${this.filterUser}`;
                        fetch(url).then(r => r.json()).then(d => { this.reports = Array.isArray(d) ? d : []; }).catch(() => {});
                    },
                    submitReport() {
                        fetch('api/reports.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.reportForm)
                        }).then(r => r.json()).then(d => {
                            if (d.success) {
                                this.showSubmitModal = false;
                                this.reportForm.content = '';
                                this.reportForm.tasks_completed = '';
                                this.reportForm.blockers = '';
                                this.loadReports();
                                alert('Report submitted successfully!');
                            } else {
                                alert(d.error || 'Failed to submit report');
                            }
                        }).catch(() => alert('Network error'));
                    }
                };
            }
            </script>

            <!-- Active Vacancies -->
            <div class="bg-white rounded-lg shadow mb-6" x-data="vacanciesManager()" x-init="init()">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold">Active Vacancies</h2>
                    <button @click="openModal()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>Add Vacancy
                    </button>
                </div>
                <div class="p-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left bg-gray-50 border-b">
                                <th class="pb-3 px-2 font-semibold">Position</th>
                                <th class="pb-3 px-2 font-semibold">Location</th>
                                <th class="pb-3 px-2 font-semibold">Type</th>
                                <th class="pb-3 px-2 font-semibold">Salary</th>
                                <th class="pb-3 px-2 font-semibold">Status</th>
                                <th class="pb-3 px-2 font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="v in vacancies" :key="v.id">
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="py-2 px-2">
                                        <span x-text="v.title"></span>
                                        <span x-show="v.urgent == 1" class="ml-2 bg-red-100 text-red-800 px-2 py-0.5 rounded-full text-xs">Urgent</span>
                                    </td>
                                    <td class="py-2 px-2" x-text="v.location"></td>
                                    <td class="py-2 px-2" x-text="v.type"></td>
                                    <td class="py-2 px-2" x-text="v.salary"></td>
                                    <td class="py-2 px-2">
                                        <span :class="v.is_active == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" class="px-2 py-0.5 rounded-full text-xs" x-text="v.is_active == 1 ? 'Active' : 'Inactive'"></span>
                                    </td>
                                    <td class="py-2 px-2">
                                        <button @click="editVacancy(v)" class="text-blue-600 hover:text-blue-800 mr-3" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button @click="deleteVacancy(v.id)" class="text-red-600 hover:text-red-800" title="Delete"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="vacancies.length === 0">
                                <td colspan="6" class="text-center text-gray-400 py-8">No active vacancies found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Vacancy Modal -->
                <div x-show="showModal" @click.outside="showModal = false; resetForm()" @keydown.escape.window="showModal = false; resetForm()" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white rounded-lg p-6 w-[480px] max-h-[90vh] overflow-y-auto">
                        <h3 class="text-lg font-bold mb-4" x-text="editMode ? 'Edit Vacancy' : 'Add Vacancy'"></h3>
                        <form @submit.prevent="saveVacancy">
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Position Title *</label>
                                <input type="text" x-model="form.title" class="w-full border rounded px-3 py-2" required></div>
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Location *</label>
                                <input type="text" x-model="form.location" class="w-full border rounded px-3 py-2" required></div>
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Job Type *</label>
                                <select x-model="form.type" class="w-full border rounded px-3 py-2" required>
                                    <option value="">Select type</option>
                                    <option value="Full-time">Full-time</option>
                                    <option value="Part-time">Part-time</option>
                                    <option value="Contract">Contract</option>
                                    <option value="Temporary">Temporary</option>
                                </select></div>
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Salary Range *</label>
                                <input type="text" x-model="form.salary" class="w-full border rounded px-3 py-2" placeholder="e.g. ₹8,000-12,000/month" required></div>
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Description</label>
                                <textarea x-model="form.description" class="w-full border rounded px-3 py-2" rows="3"></textarea></div>
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Requirements</label>
                                <textarea x-model="form.requirements" class="w-full border rounded px-3 py-2" rows="2"></textarea></div>
                            <div class="mb-3 flex items-center gap-4">
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" x-model="form.urgent" class="w-4 h-4"> Mark as Urgent
                                </label>
                                <template x-if="editMode">
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" x-model="form.is_active" class="w-4 h-4"> Active
                                    </label>
                                </template>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="showModal = false; resetForm()" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save Vacancy</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <script>
            function vacanciesManager() {
                return {
                    vacancies: [],
                    showModal: false,
                    editMode: false,
                    form: { id: null, title: '', location: '', type: '', salary: '', description: '', requirements: '', urgent: false, is_active: true },
                    init() { this.loadVacancies(); },
                    loadVacancies() {
                        fetch('api/vacancies.php?all=1')
                            .then(r => r.json()).then(d => { this.vacancies = Array.isArray(d) ? d : []; }).catch(() => {});
                    },
                    openModal() { this.editMode = false; this.resetForm(); this.showModal = true; },
                    editVacancy(v) {
                        this.editMode = true;
                        this.form = { id: v.id, title: v.title, location: v.location, type: v.type, salary: v.salary, description: v.description || '', requirements: v.requirements || '', urgent: v.urgent == 1, is_active: v.is_active == 1 };
                        this.showModal = true;
                    },
                    resetForm() { this.form = { id: null, title: '', location: '', type: '', salary: '', description: '', requirements: '', urgent: false, is_active: true }; },
                    saveVacancy() {
                        const payload = Object.assign({}, this.form);
                        fetch('api/vacancies.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                            .then(r => r.json()).then(d => {
                                if (d.success) { this.showModal = false; this.resetForm(); this.loadVacancies(); }
                                else alert(d.error || 'Failed to save vacancy');
                            }).catch(() => alert('Network error'));
                    },
                    deleteVacancy(id) {
                        if (!confirm('Deactivate this vacancy?')) return;
                        fetch('api/vacancies.php?id=' + id, { method: 'DELETE' })
                            .then(r => r.json()).then(d => { if (d.success) this.loadVacancies(); }).catch(() => {});
                    }
                };
            }
            </script>

            <!-- Quotations Management -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold">Quotations</h2>
                    <button @click="showQuoteModal = true"
                            class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>New Quotation
                    </button>
                </div>
                <div class="p-4">
                    <table class="w-full">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="pb-2">Quote #</th>
                                <th class="pb-2">Customer</th>
                                <th class="pb-2">Total</th>
                                <th class="pb-2">Status</th>
                                <th class="pb-2">Valid Until</th>
                                <th class="pb-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody x-html="quotationsList"></tbody>
                    </table>
                </div>
            </div>

            <!-- Service Enquiries -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4 border-b">
                    <h2 class="text-xl font-bold">Service Enquiries</h2>
                </div>
                <div class="p-4">
                    <table class="w-full">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="pb-2">Date</th>
                                <th class="pb-2">Customer</th>
                                <th class="pb-2">Service</th>
                                <th class="pb-2">Status</th>
                                <th class="pb-2">Assigned To</th>
                                <th class="pb-2">Action Status</th>
                                <th class="pb-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody x-html="enquiriesList"></tbody>
                    </table>
                </div>
            </div>

            <!-- Holidays and Events Planner -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold">Holidays and Events</h2>
                    <div class="flex gap-2">
                        <button @click="showEventModal = true"
                                class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                            <i class="fas fa-calendar-plus mr-2"></i>Add Event
                        </button>
                        <button @click="showHolidayModal = true"
                                class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                            <i class="fas fa-plus mr-2"></i>Declare Holiday
                        </button>
                    </div>
                </div>
                <div class="p-4">
                    <table class="w-full">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="pb-2">Holiday Date</th>
                                <th class="pb-2">Description</th>
                                <th class="pb-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody x-html="holidaysList"></tbody>
                    </table>
                </div>
            </div>

            <!-- Vacancy Modal -->
            <div x-show="showVacancyModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Add New Vacancy</h3>
                    <form @submit.prevent="saveVacancy">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Position Title</label>
                            <input type="text" x-model="vacancy.title" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Location</label>
                            <input type="text" x-model="vacancy.location" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Job Type</label>
                            <select x-model="vacancy.type" class="w-full border rounded px-3 py-2" required>
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                                <option value="Internship">Internship</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Salary Range</label>
                            <input type="text" x-model="vacancy.salary" class="w-full border rounded px-3 py-2" placeholder="e.g., ₹15,000-25,000/month">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Description</label>
                            <textarea x-model="vacancy.description" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Requirements</label>
                            <textarea x-model="vacancy.requirements" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="mb-3 flex items-center">
                            <input type="checkbox" x-model="vacancy.urgent" class="mr-2">
                            <label class="text-sm font-medium">Urgent Hiring</label>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showVacancyModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Quotation Modal -->
            <div x-show="showQuoteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-2/3 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Create New Quotation</h3>

                    <form @submit.prevent="saveQuotation">
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Customer Name</label>
                                <input type="text" x-model="quote.customer_name" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Customer Email</label>
                                <input type="email" x-model="quote.customer_email" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Customer Phone</label>
                                <input type="tel" x-model="quote.customer_phone" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Valid Until</label>
                                <input type="date" x-model="quote.valid_until" class="w-full border rounded px-3 py-2">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Items</label>
                            <table class="w-full">
                                <thead>
                                    <tr class="bg-gray-100">
                                        <th class="p-2 text-left">Description</th>
                                        <th class="p-2 text-left">Quantity</th>
                                        <th class="p-2 text-left">Unit Price</th>
                                        <th class="p-2 text-left">Total</th>
                                        <th class="p-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(item, index) in quote.items" :key="index">
                                        <tr>
                                            <td class="p-2">
                                                <input type="text" x-model="item.description" class="w-full border rounded px-2 py-1">
                                            </td>
                                            <td class="p-2">
                                                <input type="number" x-model="item.quantity" @input="calculateTotal" class="w-20 border rounded px-2 py-1">
                                            </td>
                                            <td class="p-2">
                                                <input type="number" x-model="item.unit_price" @input="calculateTotal" class="w-24 border rounded px-2 py-1">
                                            </td>
                                            <td class="p-2" x-text="item.quantity * item.unit_price"></td>
                                            <td class="p-2">
                                                <button @click="removeItem(index)" class="text-red-600">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <button @click="addItem" type="button" class="mt-2 text-blue-600">
                                <i class="fas fa-plus mr-1"></i>Add Item
                            </button>
                        </div>

                        <div class="mb-4 text-right">
                            <p>Subtotal: ₹<span x-text="quote.subtotal"></span></p>
                            <p>Tax (18%): ₹<span x-text="quote.tax"></span></p>
                            <p class="font-bold">Total: ₹<span x-text="quote.total"></span></p>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Terms & Conditions</label>
                            <textarea x-model="quote.terms" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>

                        <div class="flex justify-end space-x-2">
                            <button @click="showQuoteModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Create Quotation
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Holiday Modal -->
            <div x-show="showHolidayModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Declare Holiday</h3>
                    <form @submit.prevent="saveHoliday">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Holiday Date</label>
                            <input type="date" x-model="holiday.date" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Description</label>
                            <textarea x-model="holiday.description" class="w-full border rounded px-3 py-2" rows="3" required></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showHolidayModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- Event Modal -->
            <div x-show="showEventModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-[500px] max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Add Event</h3>
                    <form @submit.prevent="saveEvent">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Event Title</label>
                            <input type="text" x-model="eventObj.title" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Start Date</label>
                            <input type="date" x-model="eventObj.start_date" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">End Date (Optional)</label>
                            <input type="date" x-model="eventObj.end_date" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Event Type</label>
                            <select x-model="eventObj.event_type" class="w-full border rounded px-3 py-2" required>
                                <option value="general">General Event</option>
                                <option value="holiday">Holiday</option>
                                <option value="weekly-off">Weekly Off</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Description</label>
                            <textarea x-model="eventObj.description" class="w-full border rounded px-3 py-2" rows="3" required></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showEventModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">Save Event</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function management() {
                return {
                    showVacancyModal: false,
                    showQuoteModal: false,
                    showHolidayModal: false,
                    showEventModal: false,
                    recruitment: {
                        applications: '',
                        screening: '',
                        interviews: '',
                        offers: '',
                        onboarding: ''
                    },
                    vacanciesList: '',
                    quotationsList: '',
                    enquiriesList: '',
                    holidaysList: '',

                    holiday: {
                        date: '',
                        description: ''
                    },

                    eventObj: {
                        title: '',
                        start_date: '',
                        end_date: '',
                        event_type: 'general',
                        description: '',
                        target_type: 'all'
                    },

                    vacancy: {
                        title: '',
                        location: '',
                        type: 'Full-time',
                        salary: '',
                        description: '',
                        requirements: '',
                        urgent: false
                    },

                    quote: {
                        customer_name: '',
                        customer_email: '',
                        customer_phone: '',
                        valid_until: '',
                        items: [{ description: '', quantity: 1, unit_price: 0 }],
                        subtotal: 0,
                        tax: 0,
                        total: 0,
                        terms: ''
                    },

                    init() {
                        this.loadRecruitment();
                        this.loadVacancies();
                        this.loadQuotations();
                        this.loadEnquiries();
                        this.loadHolidays();
                    },

                    loadHolidays() {
                        fetch('api/holidays.php')
                            .then(res => res.text())
                            .then(data => {
                                this.holidaysList = data;
                            });
                    },

                    loadRecruitment() {
                        fetch('api/recruitment.php')
                            .then(res => res.json())
                            .then(data => {
                                this.recruitment = data;
                            });
                    },

                    loadVacancies() {
                        fetch('api/vacancies.php')
                            .then(res => res.text())
                            .then(data => {
                                this.vacanciesList = data;
                            });
                    },

                    loadQuotations() {
                        fetch('api/quotations.php')
                            .then(res => res.text())
                            .then(data => {
                                this.quotationsList = data;
                            });
                    },

                    loadEnquiries() {
                        fetch('api/enquiries.php')
                            .then(res => res.text())
                            .then(data => {
                                this.enquiriesList = data;
                            });
                    },

                                        saveVacancy() {
                        fetch('api/vacancies.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.vacancy)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showVacancyModal = false;
                                this.loadVacancies();
                                this.vacancy = {
                                    title: '',
                                    location: '',
                                    type: 'Full-time',
                                    salary: '',
                                    description: '',
                                    requirements: '',
                                    urgent: false
                                };
                                window.appNotify('Vacancy created successfully');
                            } else {
                                alert(data.error || 'Failed to create vacancy');
                            }
                        });
                    },

                    saveHoliday() {
                        fetch('api/holidays.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.holiday)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showHolidayModal = false;
                                this.loadHolidays();
                                this.holiday = {
                                    date: '',
                                    description: ''
                                };
                                window.appNotify('Holiday declared successfully');
                            } else {
                                alert(data.error || 'Failed to declare holiday');
                            }
                        });
                    },

                    deleteHoliday(id, type = 'holiday') {
                        if (confirm(`Are you sure you want to delete this ${type}?`)) {
                            fetch(`api/holidays.php?id=${id}&type=${type}`, {
                                method: 'DELETE'
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.loadHolidays();
                                    window.appNotify(`${type.charAt(0).toUpperCase() + type.slice(1)} deleted`);
                                } else {
                                    alert(data.error || `Failed to delete ${type}`);
                                }
                            });
                        }
                    },

                    saveEvent() {
                        fetch('api/holidays.php?action=event', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.eventObj)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showEventModal = false;
                                this.loadHolidays();
                                this.eventObj = {
                                    title: '',
                                    start_date: '',
                                    end_date: '',
                                    event_type: 'general',
                                    description: '',
                                    target_type: 'all'
                                };
                                window.appNotify('Event created successfully');
                            } else {
                                alert(data.error || 'Failed to create event');
                            }
                        });
                    },

                    editVacancy(vacancy) {
                        this.vacancy = {...vacancy};
                        this.showVacancyModal = true;
                    },

                    deleteVacancy(id) {
                        if (confirm('Are you sure you want to delete this vacancy?')) {
                            fetch(`api/vacancies.php?id=${id}`, {
                                method: 'DELETE'
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.loadVacancies();
                                    window.appNotify('Vacancy deleted');
                                }
                            });
                        }
                    },

                    addItem() {
                        this.quote.items.push({ description: '', quantity: 1, unit_price: 0 });
                    },

                    removeItem(index) {
                        this.quote.items.splice(index, 1);
                        this.calculateTotal();
                    },

                    calculateTotal() {
                        let subtotal = 0;
                        this.quote.items.forEach(item => {
                            subtotal += item.quantity * item.unit_price;
                        });
                        this.quote.subtotal = subtotal;
                        this.quote.tax = subtotal * 0.18;
                        this.quote.total = subtotal + this.quote.tax;
                    },
                    
                                        viewQuote(id) {
                        window.open(`api/export.php?type=quotation&id=${id}&format=pdf`, '_blank');
                    },

                    downloadQuote(id) {
                        window.location.href = `api/export.php?type=quotation&id=${id}&format=pdf&download=1`;
                    },

                    duplicateQuote(id) {
                        fetch(`api/quotations.php?duplicate=${id}`)
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.loadQuotations();
                                    window.appNotify('Quote duplicated');
                                }
                            });
                    },

                    deleteQuote(id) {
                        if (confirm('Are you sure you want to delete this quotation?')) {
                            fetch(`api/quotations.php?id=${id}`, {
                                method: 'DELETE'
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.loadQuotations();
                                    window.appNotify('Quote deleted');
                                }
                            });
                        }
                    },

                    saveQuotation() {
                        fetch('api/quotations.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.quote)
                        })
                        .then(res => res.json())
                        .then(data => {
                            this.showQuoteModal = false;
                            this.loadQuotations();
                            window.appNotify('Quotation created successfully');
                        });
                    }
                }
            }
        </script>
        <?php
    }

    private function renderProfile() {
        $user = $this->user;
        $year = date('y');
        $month = strtoupper(date('M', strtotime('2026-' . date('m') . '-01')));
        $hexCode = str_pad(dechex($user['id']), 3, '0', STR_PAD_LEFT);
        $employee_code = 'DKA' . $year . $month . $hexCode;
        
        $qr_data = json_encode([
            'id' => $user['id'],
            'employee_id' => $user['employee_id'],
            'employee_code' => $employee_code,
            'name' => $user['full_name'],
            'role' => $user['role'],
            'type' => 'staff'
        ]);
        ?>
        <div x-data="profile()" x-init="init()">
            <h1 class="text-3xl font-bold mb-6">Profile Plus</h1>

            <div class="grid grid-cols-3 gap-6">
                <!-- Digital ID Card -->
                <div class="col-span-1">
                    <div class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="bg-gradient-to-r from-blue-600 to-blue-800 p-4 text-white">
                            <h3 class="font-bold">Staff ID Card</h3>
                        </div>
                        <div class="p-4">
                            <!-- ID Card Preview -->
                            <div class="id-card-preview mx-auto mb-4" id="idCardPreview">
                                <div class="logo">
                                    <?php if (filter_var($this->settings['site_logo'] ?? '', FILTER_VALIDATE_URL)): ?>
                                        <img src="<?php echo $this->settings['site_logo']; ?>" alt="Logo" style="max-width: 100%; max-height: 100%;">
                                    <?php else: ?>
                                        <span style="font-size: 20px;"><?php echo $this->settings['site_logo'] ?? 'DK'; ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($user['photo_url']): ?>
                                <img src="<?php echo $user['photo_url']; ?>" class="photo">
                                <?php endif; ?>
                                <div style="position: absolute; top: 5mm; left: 25mm;">
                                    <div style="font-weight: bold; font-size: 12pt;"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <div style="font-size: 10pt; color: #666;"><?php echo ucfirst($user['role']); ?></div>
                                    <div style="font-size: 8pt; color: #999;">ID: <?php echo $employee_code; ?></div>
                                    <div style="font-size: 8pt; color: #999;">RO: <?php echo $user['reporting_office'] ?? 'Head Office'; ?></div>
                                    <?php if ($user['blood_group']): ?>
                                    <div style="font-size: 8pt; color: #999;">Blood: <?php echo $user['blood_group']; ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="qr" id="qrCodePreview"></div>
                            </div>

                            <button @click="downloadIDCard" class="bg-blue-600 text-white px-4 py-2 rounded w-full">
                                <i class="fas fa-download mr-2"></i>Download ID Card
                            </button>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="bg-white rounded-lg shadow mt-4">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">Quick Actions</h3>
                        </div>
                        <div class="p-4 space-y-2">
                            <button @click="showLeaveModal = true" class="w-full text-left px-3 py-2 hover:bg-gray-100 rounded">
                                <i class="fas fa-calendar-alt mr-2 text-blue-600"></i>Apply Leave
                            </button>
                            <button @click="showExpenseModal = true" class="w-full text-left px-3 py-2 hover:bg-gray-100 rounded">
                                <i class="fas fa-receipt mr-2 text-green-600"></i>Submit Expense
                            </button>
                            <button @click="showDocumentRequest = true" class="w-full text-left px-3 py-2 hover:bg-gray-100 rounded">
                                <i class="fas fa-file mr-2 text-yellow-600"></i>Request Document
                            </button>
                            <!-- Profile photo upload -->
                            <label class="w-full text-left px-3 py-2 hover:bg-gray-100 rounded cursor-pointer flex items-center">
                                <i class="fas fa-camera mr-2 text-purple-600"></i>Change Profile Photo
                                <input type="file" accept="image/jpeg,image/png,image/gif" class="hidden" @change="uploadProfilePhoto($event)">
                            </label>
                            <button @click="showPasswordModal = true" class="w-full text-left px-3 py-2 hover:bg-gray-100 rounded">
                                <i class="fas fa-key mr-2 text-red-600"></i>Change Password
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="col-span-2 space-y-4">
                    <!-- Personal Information -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b flex justify-between items-center">
                            <h3 class="font-bold">Personal Information</h3>
                            <button @click="showUpdateRequestModal = true" class="text-blue-600 text-sm">
                                <i class="fas fa-edit mr-1"></i>Request Update
                            </button>
                        </div>
                        <div class="p-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-sm text-gray-500">Full Name</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Employee Code</p>
                                    <p class="font-medium"><?php echo $employee_code; ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Email</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($user['email']); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Phone</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($user['phone']); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Department</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($user['department'] ?? 'Not set'); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Reporting Head</p>
                                    <p class="font-medium" x-text="reportingHeadName"></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Date of Joining</p>
                                    <p class="font-medium"><?php echo date('d M Y', strtotime($user['created_at'])); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Documents -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">My Documents</h3>
                        </div>
                        <div class="p-4">
                            <div class="grid grid-cols-3 gap-4">
                                <div class="border rounded p-3 text-center cursor-pointer" @click="viewDocument('offer_letter')">
                                    <i class="fas fa-file-pdf text-3xl text-red-600 mb-2"></i>
                                    <p class="text-sm">Offer Letter</p>
                                </div>
                                <div class="border rounded p-3 text-center cursor-pointer" @click="viewDocument('id_proof')">
                                    <i class="fas fa-id-card text-3xl text-blue-600 mb-2"></i>
                                    <p class="text-sm">ID Proof</p>
                                </div>
                                <div class="border rounded p-3 text-center cursor-pointer" @click="viewDocument('bank_details')">
                                    <i class="fas fa-university text-3xl text-green-600 mb-2"></i>
                                    <p class="text-sm">Bank Details</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Time & Attendance -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">Time & Attendance</h3>
                        </div>
                        <div class="p-4">
                            <div class="grid grid-cols-4 gap-4 mb-4">
                                <div class="text-center">
                                    <p class="text-2xl font-bold text-green-600" x-text="attendance.present"></p>
                                    <p class="text-xs text-gray-600">Present Days</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-2xl font-bold text-yellow-600" x-text="attendance.late"></p>
                                    <p class="text-xs text-gray-600">Late Days</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-2xl font-bold text-purple-600" x-text="attendance.leave"></p>
                                    <p class="text-xs text-gray-600">Leaves</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-2xl font-bold text-red-600" x-text="attendance.absent"></p>
                                    <p class="text-xs text-gray-600">Absent</p>
                                </div>
                            </div>

                            <div class="border rounded">
                                <table class="w-full">
                                    <thead class="bg-gray-100">
                                        <tr>
                                            <th class="p-2 text-left">Date</th>
                                            <th class="p-2 text-left">Punch In</th>
                                            <th class="p-2 text-left">Punch Out</th>
                                            <th class="p-2 text-left">Status</th>
                                            <th class="p-2 text-left">Location</th>
                                        </tr>
                                    </thead>
                                    <tbody x-html="attendanceHistory"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Daily Report -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">Daily Report</h3>
                        </div>
                        <div class="p-4">
                            <textarea x-model="dailyReport"
                                      placeholder="What did you work on today? Any blockers?"
                                      class="w-full border rounded p-3 h-32"></textarea>
                            <button @click="submitReport" class="mt-2 bg-blue-600 text-white px-4 py-2 rounded">
                                Submit Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Leave Application Modal -->
            <div x-show="showLeaveModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Apply for Leave</h3>
                    <form @submit.prevent="submitLeave">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Leave Type</label>
                            <select x-model="leave.type" class="w-full border rounded px-3 py-2">
                                <option value="sick">Sick Leave</option>
                                <option value="casual">Casual Leave</option>
                                <option value="annual">Annual Leave</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Start Date</label>
                            <input type="date" x-model="leave.start_date" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">End Date</label>
                            <input type="date" x-model="leave.end_date" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Reason</label>
                            <textarea x-model="leave.reason" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showLeaveModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Submit
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Expense Request Modal -->
            <div x-show="showExpenseModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Submit Expense</h3>
                    <form @submit.prevent="submitExpense">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Amount (₹)</label>
                            <input type="number" x-model="expense.amount" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Category</label>
                            <select x-model="expense.category" class="w-full border rounded px-3 py-2" required>
                                <option value="travel">Travel</option>
                                <option value="food">Food</option>
                                <option value="supplies">Office Supplies</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Description</label>
                            <textarea x-model="expense.description" class="w-full border rounded px-3 py-2" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Receipt</label>
                            <input type="file" @change="uploadReceipt" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showExpenseModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Submit
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Document Request Modal -->
            <div x-show="showDocumentRequest" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Request Document</h3>
                    <form @submit.prevent="submitDocumentRequest">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Document Type</label>
                            <select x-model="documentRequest.type" class="w-full border rounded px-3 py-2" required>
                                <option value="salary_slip">Salary Slip</option>
                                <option value="annual_statement">Annual Statement</option>
                                <option value="offer_letter">Offer Letter</option>
                                <option value="joining_letter">Joining Letter</option>
                                <option value="profile_summary">Profile Summary</option>
                                <option value="experience_certificate">Experience Certificate</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Reason for Request</label>
                            <textarea x-model="documentRequest.reason" class="w-full border rounded px-3 py-2" rows="3" required></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showDocumentRequest = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Password Change Modal -->
            <div x-show="showPasswordModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Change Password</h3>
                    <form @submit.prevent="changePassword">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Current Password</label>
                            <input type="password" x-model="password.current" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">New Password</label>
                            <input type="password" x-model="password.new" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Confirm New Password</label>
                            <input type="password" x-model="password.confirm" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showPasswordModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Profile Update Request Modal -->
            <div x-show="showUpdateRequestModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Request Profile Update</h3>
                    <form @submit.prevent="submitUpdateRequest">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Field to Update</label>
                            <select x-model="updateRequest.field" class="w-full border rounded px-3 py-2" required>
                                <option value="phone">Phone Number</option>
                                <option value="email">Email Address</option>
                                <option value="address">Address</option>
                                <option value="blood_group">Blood Group</option>
                                <option value="emergency_contact">Emergency Contact</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">New Value</label>
                            <input type="text" x-model="updateRequest.new_value" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Reason for Change</label>
                            <textarea x-model="updateRequest.reason" class="w-full border rounded px-3 py-2" rows="3" required></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showUpdateRequestModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Submit Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function profile() {
                return {
                    showLeaveModal: false,
                    showExpenseModal: false,
                    showDocumentRequest: false,
                    showPasswordModal: false,
                    showUpdateRequestModal: false,
                    attendance: { present: 0, late: 0, leave: 0, absent: 0 },
                    attendanceHistory: '',
                    dailyReport: '',
                    reportingHeadName: '',
                    
                    leave: {
                        type: 'sick',
                        start_date: '',
                        end_date: '',
                        reason: ''
                    },
                    
                    expense: {
                        amount: '',
                        category: 'travel',
                        description: '',
                        receipt: null
                    },
                    
                    documentRequest: {
                        type: 'salary_slip',
                        reason: ''
                    },
                    
                    password: {
                        current: '',
                        new: '',
                        confirm: ''
                    },
                    
                    updateRequest: {
                        field: 'phone',
                        new_value: '',
                        reason: ''
                    },

                    init() {
                        this.loadAttendance();
                        this.loadReportingHead();
                        this.generateQR();
                    },

                    loadAttendance() {
                        fetch('api/attendance.php?my=1')
                            .then(res => res.json())
                            .then(data => {
                                this.attendance = data.summary;
                                this.attendanceHistory = data.history;
                            });
                    },

                    loadReportingHead() {
                        fetch('api/users.php?reporting_head=1')
                            .then(res => res.json())
                            .then(data => {
                                this.reportingHeadName = data.name;
                            });
                    },

                    generateQR() {
                        const qrData = <?php echo json_encode($qr_data); ?>;
                        QRCode.toCanvas(document.getElementById('qrCodePreview'), JSON.stringify(qrData), {
                            width: 50,
                            margin: 0
                        });
                    },

                                        downloadIDCard() {
                        const user = <?php echo json_encode($user); ?>;
                        const year = new Date().getFullYear().toString().slice(-2);
                        const month = new Date().toLocaleString('default', { month: 'short' }).toUpperCase();
                        const hexCode = user.id.toString(16).toUpperCase().padStart(3, '0');
                        const employeeCode = `DKA${year}${month}${hexCode}`;
                        
                        // Get company logo from settings
                        const logo = this.$root.settings?.site_logo || '🏢';
                        const logoHtml = logo.startsWith('http') ? 
                            `<img src="${logo}" style="max-width: 100%; max-height: 100%;">` : 
                            `<span style="font-size: 24px;">${logo}</span>`;
                        
                        // Create temporary container for ID card
                        const tempDiv = document.createElement('div');
                        tempDiv.style.position = 'absolute';
                        tempDiv.style.left = '-9999px';
                        tempDiv.style.top = '-9999px';
                        tempDiv.innerHTML = `
                            <div class="id-card-preview" style="width: 85.6mm; height: 53.98mm; background: white; border: 1px solid #ccc; border-radius: 3mm; padding: 5mm; position: relative; font-family: Arial, sans-serif; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                                <div class="logo" style="position: absolute; top: 5mm; left: 5mm; width: 15mm; height: 15mm; display: flex; align-items: center; justify-content: center;">
                                    ${logoHtml}
                                </div>
                                <img src="${user.photo_url || 'https://via.placeholder.com/80x80?text=Photo'}" 
                                     style="position: absolute; top: 5mm; right: 5mm; width: 20mm; height: 20mm; border-radius: 2mm; object-fit: cover; border: 1px solid #ddd;">
                                <div style="position: absolute; top: 5mm; left: 25mm; right: 30mm;">
                                    <div style="font-weight: bold; font-size: 12pt; margin-bottom: 2px;">${user.full_name}</div>
                                    <div style="font-size: 10pt; color: #666; margin-bottom: 2px;">${user.role}</div>
                                    <div style="font-size: 8pt; color: #999; margin-bottom: 2px;">ID: ${employeeCode}</div>
                                    <div style="font-size: 8pt; color: #999; margin-bottom: 2px;">RO: ${user.reporting_office || 'Head Office'}</div>
                                    ${user.blood_group ? `<div style="font-size: 8pt; color: #999;">Blood: ${user.blood_group}</div>` : ''}
                                </div>
                                <div class="qr" style="position: absolute; bottom: 5mm; right: 5mm; width: 15mm; height: 15mm;" id="temp-qr-staff"></div>
                                <div class="footer" style="position: absolute; bottom: 5mm; left: 5mm; font-size: 6pt; color: #999;">
                                    www.hidk.in | Authorized Personnel
                                </div>
                            </div>
                        `;
                        
                        document.body.appendChild(tempDiv);
                        
                        // Generate QR
                        const qrData = JSON.stringify({
                            id: user.id,
                            employee_code: employeeCode,
                            name: user.full_name,
                            role: user.role,
                            type: 'staff'
                        });
                        
                        const tempCanvas = document.createElement('canvas');
                        QRCode.toCanvas(tempCanvas, qrData, { width: 50, margin: 0 }, function(error) {
                            if (error) {
                                console.error('QR Error:', error);
                                document.body.removeChild(tempDiv);
                                return;
                            }
                            
                            // Replace QR placeholder
                            const qrPlaceholder = document.getElementById('temp-qr-staff');
                            if (qrPlaceholder) {
                                tempCanvas.style.width = '100%';
                                tempCanvas.style.height = '100%';
                                qrPlaceholder.appendChild(tempCanvas);
                            }
                            
                            // Capture and download
                            html2canvas(tempDiv.firstChild, {
                                scale: 2,
                                backgroundColor: '#ffffff'
                            }).then(canvas => {
                                const link = document.createElement('a');
                                link.download = `Staff_ID_${employeeCode}.png`;
                                link.href = canvas.toDataURL('image/png');
                                link.click();
                                
                                // Clean up
                                document.body.removeChild(tempDiv);
                            });
                        });
                    },

                    viewDocument(type) {
                        fetch(`api/documents.php?type=${type}`)
                            .then(res => res.blob())
                            .then(blob => {
                                const url = window.URL.createObjectURL(blob);
                                window.open(url);
                            });
                    },

                    submitReport() {
                        fetch('api/reports.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ report: this.dailyReport })
                        })
                        .then(() => {
                            this.dailyReport = '';
                            window.appNotify('Report submitted');
                        });
                    },

                    submitLeave() {
                        fetch('api/leaves.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.leave)
                        })
                        .then(() => {
                            this.showLeaveModal = false;
                            window.appNotify('Leave application submitted');
                        });
                    },

                    uploadProfilePhoto(event) {
                        const file = event.target.files[0];
                        if (!file) return;
                        const allowed = ['image/jpeg', 'image/png', 'image/gif'];
                        if (!allowed.includes(file.type)) {
                            alert('Only JPG, PNG, GIF images are allowed'); return;
                        }
                        if (file.size > 5 * 1024 * 1024) {
                            alert('Image must be under 5MB'); return;
                        }
                        const formData = new FormData();
                        formData.append('photo', file);
                        fetch('api/profile_requests.php', { method: 'POST', body: formData })
                            .then(r => r.json())
                            .then(data => {
                                if (data.success) {
                                    window.appNotify('📸 Photo uploaded! Pending manager approval.');
                                } else {
                                    alert(data.error || 'Failed to upload photo');
                                }
                            })
                            .catch(() => alert('Network error'));
                    },

                    uploadReceipt(e) {
                        const file = e.target.files[0];
                        const formData = new FormData();
                        formData.append('receipt', file);

                        fetch('api/upload.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            this.expense.receipt = data.url;
                        });
                    },

                                        submitExpense() {
                        if (!this.expense.amount || !this.expense.category || !this.expense.description) {
                            alert('Please fill all required fields');
                            return;
                        }

                        fetch('api/expenses.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.expense)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showExpenseModal = false;
                                this.expense = {
                                    amount: '',
                                    category: 'travel',
                                    description: '',
                                    receipt: null
                                };
                                window.appNotify('Expense request submitted');
                            } else {
                                alert(data.error || 'Failed to submit expense');
                            }
                        });
                    },

                    submitDocumentRequest() {
                        if (!this.documentRequest.type || !this.documentRequest.reason) {
                            alert('Please fill all required fields');
                            return;
                        }

                        fetch('api/document_requests.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.documentRequest)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showDocumentRequest = false;
                                this.documentRequest = {
                                    type: 'salary_slip',
                                    reason: ''
                                };
                                window.appNotify('Document request submitted');
                            } else {
                                alert(data.error || 'Failed to submit request');
                            }
                        });
                    },

                    viewDocument(type) {
                        fetch(`api/documents.php?type=${type}`)
                            .then(res => {
                                if (res.ok) {
                                    return res.blob();
                                }
                                throw new Error('Document not found');
                            })
                            .then(blob => {
                                const url = window.URL.createObjectURL(blob);
                                window.open(url);
                            })
                            .catch(error => {
                                alert('Document not available. Please request it.');
                            });
                    },

                    changePassword() {
                        if (this.password.new !== this.password.confirm) {
                            alert('New passwords do not match');
                            return;
                        }

                        fetch('api/users.php', {
                            method: 'PUT',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ action: 'change_password', passwords: this.password })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showPasswordModal = false;
                                this.password = { current: '', new: '', confirm: '' };
                                window.appNotify('Password changed successfully');
                            } else {
                                alert(data.error || 'Failed to change password');
                            }
                        });
                    },

                    submitUpdateRequest() {
                        fetch('api/profile_requests.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.updateRequest)
                        })
                        .then(() => {
                            this.showUpdateRequestModal = false;
                            window.appNotify('Update request submitted for approval');
                        });
                    }
                }
            }
        </script>
        <?php
    }

    private function renderWorkers() {
        ?>
        <div x-data="workers()" x-init="init()">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-bold">Workers Management</h1>
                <button @click="showAddWorker = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    <i class="fas fa-plus mr-2"></i>Add Worker
                </button>
            </div>

            <!-- Workers Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <template x-for="worker in workers" :key="worker.id">
                    <div class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="p-4">
                            <div class="flex items-center space-x-3">
                                <img :src="worker.photo_url || 'https://via.placeholder.com/50'"
                                     class="w-12 h-12 rounded-full object-cover">
                                <div>
                                    <h3 class="font-bold" x-text="worker.name"></h3>
                                    <p class="text-sm text-gray-600" x-text="worker.skills"></p>
                                </div>
                            </div>

                            <div class="mt-3 space-y-1 text-sm">
                                <p><i class="fas fa-phone w-4 text-gray-400"></i> <span x-text="worker.phone"></span></p>
                                <p><i class="fas fa-map-marker-alt w-4 text-gray-400"></i> <span x-text="worker.address"></span></p>
                                <p><i class="fas fa-star w-4 text-yellow-400"></i> <span x-text="worker.rating + ' / 5'"></span></p>
                                <p><i class="fas fa-user-tie w-4 text-gray-400"></i> <span x-text="worker.supervisor || 'Not assigned'"></span></p>
                            </div>

                            <div class="mt-3 flex justify-between items-center">
                                <span :class="{
                                    'bg-green-100 text-green-800': worker.status === 'active',
                                    'bg-gray-100 text-gray-800': worker.status === 'inactive'
                                }" class="px-2 py-1 rounded-full text-xs" x-text="worker.status"></span>

                                <div class="flex space-x-2">
                                    <button @click="viewWorker(worker)" class="text-blue-600 hover:text-blue-800">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button @click="generateIDCard(worker)" class="text-green-600 hover:text-green-800">
                                        <i class="fas fa-id-card"></i>
                                    </button>
                                    <button @click="editWorker(worker)" class="text-yellow-600 hover:text-yellow-800">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- QR Code -->
                            <div class="mt-3 text-center">
                                <div :id="'qr-' + worker.id" class="inline-block"></div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Add/Edit Worker Modal -->
            <div x-show="showAddWorker" @click.outside="showAddWorker = false" @keydown.escape.window="showAddWorker = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-2/3 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4" x-text="editingWorker ? 'Edit Worker' : 'Add New Worker'"></h3>

                    <form @submit.prevent="saveWorker">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Full Name *</label>
                                <input type="text" x-model="workerForm.name" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Father's Name</label>
                                <input type="text" x-model="workerForm.father_name" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Date of Birth</label>
                                <input type="date" x-model="workerForm.dob" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Gender</label>
                                <select x-model="workerForm.gender" class="w-full border rounded px-3 py-2">
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Phone *</label>
                                <input type="tel" x-model="workerForm.phone" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Email</label>
                                <input type="email" x-model="workerForm.email" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-sm font-medium mb-1">Address</label>
                                <textarea x-model="workerForm.address" class="w-full border rounded px-3 py-2" rows="2"></textarea>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-sm font-medium mb-1">Skills (comma separated)</label>
                                <input type="text" x-model="workerForm.skills" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Experience</label>
                                <input type="text" x-model="workerForm.experience" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Qualification</label>
                                <input type="text" x-model="workerForm.qualification" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Blood Group</label>
                                <select x-model="workerForm.blood_group" class="w-full border rounded px-3 py-2">
                                    <option value="">Select Blood Group</option>
                                    <option value="A+">A+</option>
                                    <option value="A-">A-</option>
                                    <option value="B+">B+</option>
                                    <option value="B-">B-</option>
                                    <option value="O+">O+</option>
                                    <option value="O-">O-</option>
                                    <option value="AB+">AB+</option>
                                    <option value="AB-">AB-</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Supervisor</label>
                                <select x-model="workerForm.supervisor" class="w-full border rounded px-3 py-2">
                                    <option value="">Select Supervisor</option>
                                    <template x-for="user in users" :key="user.id">
                                        <option :value="user.full_name" x-text="user.full_name"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Photo</label>
                                <input type="file" @change="uploadPhoto" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Status</label>
                                <select x-model="workerForm.status" class="w-full border rounded px-3 py-2">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="on_leave">On Leave</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-2 mt-4">
                            <button @click="showAddWorker = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Save Worker
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function workers() {
                return {
                    workers: [],
                    users: [],
                    showAddWorker: false,
                    editingWorker: null,
                    workerForm: {
                        name: '',
                        father_name: '',
                        dob: '',
                        gender: '',
                        phone: '',
                        email: '',
                        address: '',
                        skills: '',
                        experience: '',
                        qualification: '',
                        blood_group: '',
                        supervisor: '',
                        status: 'active'
                    },

                    init() {
                        this.loadWorkers();
                        this.loadUsers();
                    },

                    loadWorkers() {
                        fetch('api/workers.php')
                                                       .then(res => res.json())
                            .then(data => {
                                this.workers = data;
                                this.$nextTick(() => {
                                    this.workers.forEach(worker => {
                                        this.generateQR(worker);
                                    });
                                });
                            });
                    },

                    loadUsers() {
                        fetch('api/users.php?list=1')
                            .then(res => res.json())
                            .then(data => {
                                this.users = data;
                            });
                    },

                    generateQR(worker) {
                        const year = new Date().getFullYear().toString().slice(-2);
                        const month = new Date().toLocaleString('default', { month: 'short' }).toUpperCase();
                        const hexCode = worker.id.toString(16).toUpperCase().padStart(3, '0');
                        const workerCode = `DKW${year}${month}${hexCode}`;
                        
                        const qrData = JSON.stringify({
                            id: worker.id,
                            worker_id: worker.worker_id,
                            worker_code: workerCode,
                            name: worker.name,
                            type: 'worker'
                        });

                        QRCode.toCanvas(document.getElementById('qr-' + worker.id), qrData, {
                            width: 100,
                            margin: 1
                        });
                    },

                    saveWorker() {
                        const url = this.editingWorker ? `api/workers.php?id=${this.editingWorker.id}` : 'api/workers.php';
                        const method = this.editingWorker ? 'PUT' : 'POST';

                        fetch(url, {
                            method: method,
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.workerForm)
                        })
                        .then(res => res.json())
                        .then(data => {
                            this.showAddWorker = false;
                            this.loadWorkers();
                            window.appNotify(
                                this.editingWorker ? 'Worker updated' : 'Worker added'
                            );
                        });
                    },

                    uploadPhoto(e) {
                        const file = e.target.files[0];
                        const formData = new FormData();
                        formData.append('photo', file);

                        fetch('api/upload.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            this.workerForm.photo_url = data.url;
                        });
                    },

                    viewWorker(worker) {
                        const modalHtml = `
                            <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" id="viewWorkerModal">
                                <div class="bg-white rounded-lg p-6 w-2/3 max-h-screen overflow-y-auto">
                                    <div class="flex justify-between items-center mb-4">
                                        <h3 class="text-xl font-bold">Worker Details</h3>
                                        <button onclick="document.getElementById('viewWorkerModal').remove()" class="text-gray-500 hover:text-gray-700">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="grid grid-cols-3 gap-4">
                                        <!-- Photo Column -->
                                        <div class="col-span-1">
                                            <div class="bg-gray-100 rounded-lg p-4 text-center">
                                                <img src="${worker.photo_url || 'https://via.placeholder.com/150'}" 
                                                     class="w-32 h-32 rounded-full mx-auto mb-3 object-cover">
                                                <h4 class="font-bold text-lg">${worker.name}</h4>
                                                <p class="text-gray-600">${worker.skills || 'No skills listed'}</p>
                                                <span class="inline-block px-3 py-1 rounded-full text-sm mt-2 ${
                                                    worker.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                                                }">
                                                    ${worker.status}
                                                </span>
                                            </div>
                                            
                                            <div class="mt-4 bg-gray-50 rounded-lg p-4">
                                                <h5 class="font-bold mb-2">Details</h5>
                                                <div class="space-y-2 text-sm text-gray-600">
                                                     <p><strong>Code:</strong> ${worker.worker_code || 'N/A'}</p>
                                                     <p><strong>Phone:</strong> ${worker.phone || 'N/A'}</p>
                                                     <p><strong>Date Added:</strong> ${worker.created_at || 'N/A'}</p>
                                                     <p><strong>Blood Group:</strong> ${worker.blood_group || 'N/A'}</p>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Details Column -->
                                        <div class="col-span-2">
                                            <div class="bg-white border rounded-lg p-4">
                                                <h5 class="font-bold mb-3">Professional Information</h5>
                                                <div class="grid grid-cols-2 gap-4">
                                                    <div>
                                                        <p class="text-sm text-gray-500">Skills</p>
                                                        <p class="font-medium">${worker.skills || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Experience</p>
                                                        <p class="font-medium">${worker.experience || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Qualification</p>
                                                        <p class="font-medium">${worker.qualification || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Supervisor</p>
                                                        <p class="font-medium">${worker.supervisor || 'Not assigned'}</p>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="bg-white border rounded-lg p-4 mt-4">
                                                <h5 class="font-bold mb-3">Address Information</h5>
                                                <p class="font-medium">${worker.address || 'Not provided'}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = modalHtml;
                        document.body.appendChild(tempDiv.firstChild);
                    },

                    editWorker(worker) {
                        this.editingWorker = worker;
                        this.workerForm = { ...worker };
                        this.showAddWorker = true;
                    },

                    generateIDCard(worker) {
                        const year = new Date().getFullYear().toString().slice(-2);
                        const month = new Date().toLocaleString('default', { month: 'short' }).toUpperCase();
                        const hexCode = worker.id.toString(16).toUpperCase().padStart(3, '0');
                        const workerCode = `DKW${year}${month}${hexCode}`;
                        
                        // Create ID card HTML
                        const idCardHtml = `
                            <div style="width: 85.6mm; height: 53.98mm; background: white; border: 1px solid #ccc; border-radius: 3mm; padding: 5mm; position: relative; font-family: Arial, sans-serif;">
                                <div style="position: absolute; top: 5mm; left: 5mm; width: 15mm; height: 15mm;">
                                    <img src="${this.$root.settings.site_logo}" style="max-width: 100%; max-height: 100%;">
                                </div>
                                <img src="${worker.photo_url || 'https://via.placeholder.com/50'}" style="position: absolute; top: 5mm; right: 5mm; width: 20mm; height: 20mm; border-radius: 2mm; object-fit: cover;">
                                <div style="position: absolute; top: 5mm; left: 25mm;">
                                    <div style="font-weight: bold; font-size: 12pt;">${worker.name}</div>
                                    <div style="font-size: 10pt; color: #666;">${worker.skills || 'Worker'}</div>
                                    <div style="font-size: 8pt; color: #999;">ID: ${workerCode}</div>
                                    <div style="font-size: 8pt; color: #999;">Supervisor: ${worker.supervisor || 'Not assigned'}</div>
                                    ${worker.blood_group ? `<div style="font-size: 8pt; color: #999;">Blood: ${worker.blood_group}</div>` : ''}
                                </div>
                                <div style="position: absolute; bottom: 5mm; right: 5mm; width: 15mm; height: 15mm;" id="qr-${worker.id}"></div>
                            </div>
                        `;
                        
                        // Download as PDF or image
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = idCardHtml;
                        document.body.appendChild(tempDiv);
                        
                        html2canvas(tempDiv).then(canvas => {
                            const link = document.createElement('a');
                            link.download = `Worker_ID_${workerCode}.png`;
                            link.href = canvas.toDataURL();
                            link.click();
                            document.body.removeChild(tempDiv);
                        });
                    }
                }
            }
        </script>
        <?php
    }

    private function renderSettings() {
        ?>
        <div x-data="settings()" x-init="init()">
            <h1 class="text-3xl font-bold mb-6">Settings</h1>

            <div class="grid grid-cols-4 gap-4">
                <!-- Settings Navigation -->
                <div class="col-span-1">
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4">
                            <ul class="space-y-2">
                                <?php if ($this->user['id'] == 1): ?>
                                <li><button @click="activeSection = 'data_manage'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'data_manage'}"
                                            class="w-full text-left px-3 py-2 rounded">Data Management</button></li>
                                <?php endif; ?>
                                <li><button @click="activeSection = 'homepage'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'homepage'}"
                                            class="w-full text-left px-3 py-2 rounded">Homepage</button></li>
                                <li><button @click="activeSection = 'general'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'general'}"
                                            class="w-full text-left px-3 py-2 rounded">General</button></li>
                                <li><button @click="activeSection = 'contact'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'contact'}"
                                            class="w-full text-left px-3 py-2 rounded">Contact Info</button></li>
                                <li><button @click="activeSection = 'team'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'team'}"
                                            class="w-full text-left px-3 py-2 rounded">Team Members</button></li>
                                <li><button @click="activeSection = 'templates'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'templates'}"
                                            class="w-full text-left px-3 py-2 rounded">Templates</button></li>
                                <li><button @click="activeSection = 'email'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'email'}"
                                            class="w-full text-left px-3 py-2 rounded">Email Configuration</button></li>
                                <li><button @click="activeSection = 'payment'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'payment'}"
                                            class="w-full text-left px-3 py-2 rounded">Payment Info</button></li>
                                <li><button @click="activeSection = 'security'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'security'}"
                                            class="w-full text-left px-3 py-2 rounded">Security</button></li>
                                <li><button @click="activeSection = 'social'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'social'}"
                                            class="w-full text-left px-3 py-2 rounded">Social Media</button></li>
                                <li><button @click="activeSection = 'audit'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'audit'}"
                                            class="w-full text-left px-3 py-2 rounded">Audit Logs</button></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Settings Content -->
                <div class="col-span-3">
                    <div class="bg-white rounded-lg shadow p-6">

                        <!-- Data Management -->
                        <?php if ($this->user['id'] == 1): ?>
                        <div x-show="activeSection === 'data_manage'">
                            <h2 class="text-xl font-bold mb-4">Export & Import Utility</h2>
                            <p class="text-sm text-gray-600 mb-6">Backup your data or bulk import records via CSV/VCF.</p>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div class="border p-6 rounded-lg bg-gray-50">
                                    <h3 class="font-bold mb-4"><i class="fas fa-file-export mr-2 text-blue-600"></i>Export Data</h3>
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium mb-1">Select Table</label>
                                            <select x-model="dataManage.exportTable" class="w-full border rounded px-3 py-2">
                                                <option value="workers">Workers</option>
                                                <option value="admin_users">Staff/Users</option>
                                                <option value="applications">Job Applications</option>
                                                <option value="enquiries">Service Enquiries</option>
                                                <option value="attendance">Attendance Records</option>
                                                <option value="salary_records">Salary History</option>
                                                <option value="tasks">Tasks</option>
                                            </select>
                                        </div>
                                        <div class="flex space-x-2">
                                            <button @click="exportData('csv')" class="flex-1 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Export CSV</button>
                                            <button x-show="['workers', 'admin_users'].includes(dataManage.exportTable)" @click="exportData('vcf')" class="flex-1 bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Export VCF</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="border p-6 rounded-lg bg-gray-50">
                                    <h3 class="font-bold mb-4"><i class="fas fa-file-import mr-2 text-orange-600"></i>Import Data (CSV)</h3>
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium mb-1">Target Table</label>
                                            <select x-model="dataManage.importTable" class="w-full border rounded px-3 py-2">
                                                <option value="workers">Workers</option>
                                                <option value="admin_users">Staff/Users</option>
                                                <option value="tasks">Tasks</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium mb-1">Select CSV File</label>
                                            <input type="file" @change="dataManage.file = $event.target.files[0]" class="w-full border rounded px-3 py-2">
                                        </div>
                                        <button @click="importData" class="w-full bg-orange-600 text-white px-4 py-2 rounded hover:bg-orange-700">Run Import</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Homepage Customization -->
                        <div x-show="activeSection === 'homepage'">
                            <h2 class="text-xl font-bold mb-4">Homepage Customization</h2>
                            <div class="mb-8 border-b pb-6">
                                <h3 class="font-bold mb-3">Statistics</h3>
                                <template x-for="(stat, index) in homepage.stats" :key="index">
                                    <div class="grid grid-cols-2 gap-4 mb-2">
                                        <input type="text" x-model="stat.number" placeholder="Number" class="border rounded px-3 py-2">
                                        <div class="flex space-x-2">
                                            <input type="text" x-model="stat.label" placeholder="Label" class="flex-1 border rounded px-3 py-2">
                                            <button @click="homepage.stats.splice(index, 1)" class="text-red-600"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                </template>
                                <button @click="homepage.stats.push({number: '', label: ''})" class="text-blue-600 text-sm mt-2">+ Add Statistic</button>
                            </div>
                            <div class="mb-8 border-b pb-6">
                                <h3 class="font-bold mb-3">Why Choose Us</h3>
                                <template x-for="(point, index) in homepage.why_choose_us" :key="index">
                                    <div class="border p-4 rounded mb-4">
                                        <div class="grid grid-cols-2 gap-4 mb-2">
                                            <input type="text" x-model="point.title" placeholder="Title" class="border rounded px-3 py-2">
                                            <input type="text" x-model="point.icon" placeholder="Icon (e.g. bi-clock)" class="border rounded px-3 py-2">
                                        </div>
                                        <textarea x-model="point.description" class="w-full border rounded px-3 py-2 mt-2" rows="2"></textarea>
                                        <button @click="homepage.why_choose_us.splice(index, 1)" class="text-red-600 mt-2 text-sm">Remove</button>
                                    </div>
                                </template>
                                <button @click="homepage.why_choose_us.push({title: '', icon: '', description: ''})" class="text-blue-600 text-sm">+ Add Point</button>
                            </div>
                            <div class="mb-8 border-b pb-6">
                                <h3 class="font-bold mb-3">Service Categories</h3>
                                <template x-for="(cat, index) in homepage.service_categories" :key="index">
                                    <div class="border p-4 rounded mb-4">
                                        <div class="grid grid-cols-2 gap-4 mb-2">
                                            <input type="text" x-model="cat.title" placeholder="Title" class="border rounded px-3 py-2">
                                            <input type="text" x-model="cat.icon" placeholder="Icon" class="border rounded px-3 py-2">
                                        </div>
                                        <input type="text" x-model="cat.description" placeholder="Short description" class="w-full border rounded px-3 py-2 mb-2">
                                        <div class="mt-2">
                                            <label class="text-sm font-bold">Services (comma separated)</label>
                                            <input type="text" x-model="cat.services_text" @change="cat.services = cat.services_text.split(',').map(s => s.trim())" class="w-full border rounded px-3 py-2">
                                        </div>
                                        <button @click="homepage.service_categories.splice(index, 1)" class="text-red-600 mt-2 text-sm">Remove</button>
                                    </div>
                                </template>
                                <button @click="homepage.service_categories.push({title: '', icon: '', description: '', services: [], services_text: ''})" class="text-blue-600 text-sm">+ Add Category</button>
                            </div>
                            <button @click="saveHomepage" class="bg-blue-600 text-white px-6 py-2 rounded font-bold shadow hover:bg-blue-700">Save Homepage Changes</button>
                        </div>

                        <!-- Social Media Settings -->
                        <div x-show="activeSection === 'social'">
                            <h2 class="text-xl font-bold mb-4">Social Media Links</h2>
                            <form @submit.prevent="saveSocial">
                                <div class="space-y-4">
                                    <template x-for="(link, key) in social_media" :key="key">
                                        <div class="border p-4 rounded-lg">
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="font-medium" x-text="key.charAt(0).toUpperCase() + key.slice(1)"></span>
                                                <label class="flex items-center cursor-pointer">
                                                    <div class="relative">
                                                        <input type="checkbox" x-model="link.visible" class="sr-only">
                                                        <div class="block bg-gray-600 w-10 h-6 rounded-full"></div>
                                                        <div :class="link.visible ? 'translate-x-full bg-blue-500' : 'bg-white'" class="dot absolute left-1 top-1 w-4 h-4 rounded-full transition"></div>
                                                    </div>
                                                    <span class="ml-3 text-sm">Visible</span>
                                                </label>
                                            </div>
                                            <input type="text" x-model="link.url" class="w-full border rounded px-3 py-2" :placeholder="'Enter ' + key + ' link'" :disabled="!link.visible">
                                        </div>
                                    </template>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Social Media</button>
                                </div>
                            </form>
                        </div>

                        <!-- General Settings -->
                        <div x-show="activeSection === 'general'">
                            <h2 class="text-xl font-bold mb-4">General Settings</h2>
                            <form @submit.prevent="saveGeneral">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Site Title</label>
                                        <input type="text" x-model="settings.site_title" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Application Timezone</label>
                                        <select x-model="settings.timezone" class="w-full border rounded px-3 py-2">
                                            <option value="Asia/Kolkata">IST – Asia/Kolkata (Default)</option>
                                            <option value="Asia/Mumbai">Asia/Mumbai</option>
                                            <option value="Asia/Delhi">Asia/Delhi</option>
                                            <option value="UTC">UTC</option>
                                            <option value="Asia/Dubai">Asia/Dubai (GST)</option>
                                            <option value="America/New_York">America/New_York (EST)</option>
                                            <option value="Europe/London">Europe/London (GMT)</option>
                                        </select>
                                        <p class="text-xs text-gray-500 mt-1">This timezone applies to all timestamps, logs, and attendance records.</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Site Tagline</label>
                                        <input type="text" x-model="settings.site_tagline" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Site Logo</label>
                                        <input type="file" @change="uploadLogo" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Favicon</label>
                                        <input type="file" @change="uploadFavicon" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Founded Year</label>
                                        <input type="text" x-model="settings.founded_year" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Changes</button>
                                </div>
                            </form>
                        </div>

                        <!-- Contact Info -->
                        <div x-show="activeSection === 'contact'">
                            <h2 class="text-xl font-bold mb-4">Contact Information</h2>
                            <form @submit.prevent="saveContact">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Company Phone</label>
                                        <input type="text" x-model="settings.company_phone" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Company WhatsApp</label>
                                        <input type="text" x-model="settings.company_whatsapp" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Company Email</label>
                                        <input type="email" x-model="settings.company_email" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Company Address</label>
                                        <textarea x-model="settings.company_address" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Working Hours</label>
                                        <input type="text" x-model="settings.company_hours" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Changes</button>
                                </div>
                            </form>
                        </div>

                        <!-- Team Members -->
                        <div x-show="activeSection === 'team'">
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="text-xl font-bold">Team Members</h2>
                                <button @click="showAddTeam = true" class="bg-blue-600 text-white px-3 py-1 rounded">
                                    <i class="fas fa-plus mr-1"></i>Add Member
                                </button>
                            </div>

                            <div class="space-y-2">
                                <template x-for="member in teamMembers" :key="member.id">
                                    <div class="border rounded p-3 flex items-center justify-between">
                                        <div class="flex items-center space-x-3">
                                            <img :src="member.photo_url || 'https://via.placeholder.com/40'" class="w-10 h-10 rounded-full">
                                            <div>
                                                <p class="font-medium" x-text="member.name"></p>
                                                <p class="text-sm text-gray-600" x-text="member.position"></p>
                                            </div>
                                        </div>
                                        <div class="flex space-x-2">
                                            <button @click="editTeamMember(member)" class="text-blue-600">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button @click="deleteTeamMember(member.id)" class="text-red-600">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Templates Management -->
                        <div x-show="activeSection === 'templates'">
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="text-xl font-bold">Document Templates</h2>
                                <button @click="showTemplateModal = true" class="bg-blue-600 text-white px-3 py-1 rounded">
                                    <i class="fas fa-plus mr-1"></i>New Template
                                </button>
                            </div>

                            <div class="mb-4">
                                <select x-model="templateType" @change="loadTemplates" class="border rounded px-3 py-2">
                                    <option value="staff_id">Staff ID Card</option>
                                    <option value="worker_id">Worker ID Card</option>
                                    <option value="offer_letter">Offer Letter</option>
                                    <option value="joining_letter">Joining Letter</option>
                                    <option value="salary_slip">Salary Slip</option>
                                    <option value="annual_statement">Annual Statement</option>
                                    <option value="interview_invitation">Interview Invitation</option>
                                    <option value="deployment_letter">Deployment Letter</option>
                                    <option value="company_policies">Company Policies</option>
                                    <option value="faqs">FAQs</option>
                                    <option value="warning_letter">Warning Letter</option>
                                    <option value="termination_letter">Termination Letter</option>
                                    <option value="service_agreement">Service Agreement</option>
                                    <option value="custom">Custom Template</option>
                                </select>
                            </div>

                            <div class="space-y-2" x-html="templatesList"></div>

                            <!-- Template Editor Modal -->
                            <div x-show="showTemplateModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                                <div class="bg-white rounded-lg p-6 w-2/3 max-h-[90vh] overflow-y-auto">
                                    <h3 class="text-lg font-bold mb-4">Edit Template</h3>
                                    <form @submit.prevent="saveTemplate">
                                        <div class="mb-3">
                                            <label class="block text-sm font-medium mb-1">Template Name</label>
                                            <input type="text" x-model="templateForm.name" class="w-full border rounded px-3 py-2" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="block text-sm font-medium mb-1">HTML Content</label>
                                            <textarea x-model="templateForm.content" class="w-full border rounded px-3 py-2 font-mono" rows="10"></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="block text-sm font-medium mb-1">CSS</label>
                                            <textarea x-model="templateForm.css" class="w-full border rounded px-3 py-2 font-mono" rows="5"></textarea>
                                        </div>
                                        <div class="mb-3 flex items-center">
                                            <input type="checkbox" x-model="templateForm.is_default" class="mr-2">
                                            <label class="text-sm font-medium">Set as Default</label>
                                        </div>
                                        <div class="flex justify-end space-x-2">
                                            <button @click="showTemplateModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save Template</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>


                        <!-- Audit Logs -->
                        <div x-show="activeSection === 'audit'">
                            <h2 class="text-xl font-bold mb-4">Audit Logs</h2>
                            
                            <div class="mb-4 flex space-x-2">
                                <input type="text" x-model="auditFilters.user" placeholder="Filter by user" class="border rounded px-3 py-2">
                                <select x-model="auditFilters.action" class="border rounded px-3 py-2">
                                    <option value="">All Actions</option>
                                    <option value="login">Login</option>
                                    <option value="logout">Logout</option>
                                    <option value="failed_login">Failed Login</option>
                                    <option value="task_created">Task Created</option>
                                                                        <option value="task_completed">Task Completed</option>
                                    <option value="quote_created">Quote Created</option>
                                    <option value="worker_added">Worker Added</option>
                                    <option value="settings_updated">Settings Updated</option>
                                    <option value="user_created">User Created</option>
                                    <option value="user_updated">User Updated</option>
                                    <option value="document_downloaded">Document Downloaded</option>
                                </select>
                                <input type="date" x-model="auditFilters.date" class="border rounded px-3 py-2">
                                <button @click="loadAuditLogs" class="bg-blue-600 text-white px-4 py-2 rounded">Filter</button>
                                <button @click="exportAuditLogs" class="bg-green-600 text-white px-4 py-2 rounded">Export</button>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="text-left border-b">
                                            <th class="pb-2">Time</th>
                                            <th class="pb-2">User</th>
                                            <th class="pb-2">Action</th>
                                            <th class="pb-2">Details</th>
                                            <th class="pb-2">IP Address</th>
                                            <th class="pb-2">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody x-html="auditLogsList"></tbody>
                                </table>
                            </div>

                            <div class="mt-4 flex justify-between items-center">
                                <button @click="prevPage" :disabled="currentPage === 1" class="px-3 py-1 bg-gray-200 rounded disabled:opacity-50">Previous</button>
                                <span>Page <span x-text="currentPage"></span> of <span x-text="totalPages"></span></span>
                                <button @click="nextPage" :disabled="currentPage === totalPages" class="px-3 py-1 bg-gray-200 rounded disabled:opacity-50">Next</button>
                            </div>
                        </div>

                        <!-- Email Configuration -->
                        <div x-show="activeSection === 'email'">
                            <h2 class="text-xl font-bold mb-4">Email Configuration</h2>
                            <form @submit.prevent="saveEmail">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">SMTP Host</label>
                                        <input type="text" x-model="email.smtp_host" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">SMTP Port</label>
                                        <input type="text" x-model="email.smtp_port" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">SMTP Username</label>
                                        <input type="text" x-model="email.smtp_user" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">SMTP Password</label>
                                        <input type="password" x-model="email.smtp_pass" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">From Email</label>
                                        <input type="email" x-model="email.from_email" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">From Name</label>
                                        <input type="text" x-model="email.from_name" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Configuration</button>
                                    <button type="button" @click="testEmail" class="ml-2 bg-gray-200 px-4 py-2 rounded">Test Connection</button>
                                </div>
                            </form>
                        </div>

                        <!-- Payment Info -->
                        <div x-show="activeSection === 'payment'">
                            <h2 class="text-xl font-bold mb-4">Payment Information</h2>
                            <form @submit.prevent="savePayment">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Bank Name</label>
                                        <input type="text" x-model="payment.bank_name" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Account Holder</label>
                                        <input type="text" x-model="payment.account_holder" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Account Number</label>
                                        <input type="text" x-model="payment.account_number" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">IFSC Code</label>
                                        <input type="text" x-model="payment.ifsc_code" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">UPI ID</label>
                                        <input type="text" x-model="payment.upi_id" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Changes</button>
                                </div>
                            </form>
                        </div>

                        <!-- Security -->
                        <div x-show="activeSection === 'security'">
                            <h2 class="text-xl font-bold mb-4">Security Settings</h2>
                            <form @submit.prevent="saveSecurity">
                                <div class="space-y-4">
                                    <div class="flex items-center">
                                        <input type="checkbox" x-model="security.two_factor" class="mr-2">
                                        <label>Enable Two-Factor Authentication for Admin</label>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium mb-1">Session Timeout (minutes)</label>
                                        <input type="number" x-model="security.session_timeout" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Max Login Attempts</label>
                                        <input type="number" x-model="security.max_attempts" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Lockout Time (minutes)</label>
                                        <input type="number" x-model="security.lockout_time" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">IP Whitelist (comma separated)</label>
                                        <textarea x-model="security.ip_whitelist" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Rate Limiting (requests per minute)</label>
                                        <input type="number" x-model="security.rate_limit" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Security Settings</button>
                                </div>
                            </form>
                        </div>
                    </div>
                        </div>
                    </div>
                </div>

                <!-- Geofencing Settings Card -->
                <div class="bg-white rounded-lg shadow p-6 mt-6">
                    <h3 class="font-bold text-lg mb-4 flex items-center gap-2">
                        <i class="fas fa-map-marker-alt text-blue-600"></i> Geofencing for Attendance
                    </h3>
                    <form @submit.prevent="saveGeofence">
                        <div class="mb-4 flex items-center gap-3">
                            <input type="checkbox" id="geo_enabled" x-model="geofence.enabled" class="w-4 h-4">
                            <label for="geo_enabled" class="font-medium">Enable GPS Geofencing for Attendance</label>
                        </div>
                        <div x-show="geofence.enabled" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Office Address / Label</label>
                                <input type="text" x-model="geofence.address" class="w-full border rounded px-3 py-2" placeholder="e.g. Head Office, Rewa">
                            </div>
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1">Latitude</label>
                                    <input type="number" step="any" x-model="geofence.lat" class="w-full border rounded px-3 py-2" placeholder="e.g. 24.5374">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1">Longitude</label>
                                    <input type="number" step="any" x-model="geofence.lng" class="w-full border rounded px-3 py-2" placeholder="e.g. 81.2978">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1">Radius (meters)</label>
                                    <input type="number" x-model="geofence.radius" class="w-full border rounded px-3 py-2" placeholder="e.g. 500">
                                </div>
                            </div>
                            <div class="bg-blue-50 border border-blue-200 rounded p-3 text-sm text-blue-800">
                                <i class="fas fa-info-circle mr-1"></i>
                                <strong>Tip:</strong> Use <a href="https://maps.google.com" target="_blank" class="underline">Google Maps</a> to find coordinates. Right-click on your office location → "What's here?" to get lat/lng.
                            </div>
                            <button type="button" @click="detectLocation" class="text-sm text-blue-600 underline">
                                <i class="fas fa-crosshairs mr-1"></i> Auto-detect my current location
                            </button>

                            <hr class="my-4">
                            <h4 class="font-semibold text-gray-700 mb-2">Per-User Geofence Override</h4>
                            <p class="text-sm text-gray-500 mb-3">Set a custom geofence center/radius for a specific user (overrides global setting for that user only).</p>
                            <div class="flex gap-2">
                                <select x-model="geoOverride.user_id" class="flex-1 border rounded px-3 py-2">
                                    <option value="">Select user...</option>
                                    <template x-for="u in geoUserList" :key="u.id">
                                        <option :value="u.id" x-text="u.full_name + ' (' + u.role + ')'"></option>
                                    </template>
                                </select>
                                <button type="button" @click="loadUserGeoOverride" class="px-3 py-2 bg-gray-200 rounded text-sm">Load</button>
                            </div>
                            <div x-show="geoOverride.user_id" class="grid grid-cols-3 gap-4 mt-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1">Override Latitude</label>
                                    <input type="number" step="any" x-model="geoOverride.lat" class="w-full border rounded px-2 py-1 text-sm" placeholder="Leave empty to use global">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1">Override Longitude</label>
                                    <input type="number" step="any" x-model="geoOverride.lng" class="w-full border rounded px-2 py-1 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1">Override Radius (m)</label>
                                    <input type="number" x-model="geoOverride.radius" class="w-full border rounded px-2 py-1 text-sm" placeholder="e.g. 200">
                                </div>
                            </div>
                            <div x-show="geoOverride.user_id" class="flex gap-2 mt-2">
                                <button type="button" @click="saveUserGeoOverride" class="px-4 py-2 bg-green-600 text-white rounded text-sm">Save Override</button>
                                <button type="button" @click="clearUserGeoOverride" class="px-4 py-2 bg-red-500 text-white rounded text-sm">Clear Override</button>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Geofencing Settings</button>
                        </div>
                    </form>
                </div>
            </div>


            <!-- Add Team Member Modal -->
            <div x-show="showAddTeam" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Add Team Member</h3>
                    <form @submit.prevent="saveTeamMember">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Name</label>
                            <input type="text" x-model="teamForm.name" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Position</label>
                            <input type="text" x-model="teamForm.position" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Bio</label>
                            <textarea x-model="teamForm.bio" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Photo</label>
                            <input type="file" @change="uploadTeamPhoto" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Display Order</label>
                            <input type="number" x-model="teamForm.display_order" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showAddTeam = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Add Member</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function settings() {
                return {
                    activeSection: 'general',
                    settings: {},
                    teamMembers: [],
                    templatesList: '',
                    showAddTeam: false,
                    showTemplateModal: false,
                    templateType: 'staff_id',
                    currentPage: 1,
                    totalPages: 1,
                    
                    auditFilters: {
                        user: '',
                        action: '',
                        date: ''
                    },
                    
                    email: {
                        smtp_host: '',
                        smtp_port: '',
                        smtp_user: '',
                        smtp_pass: '',
                        from_email: '',
                        from_name: ''
                    },
                    
                    payment: {
                        bank_name: '',
                        account_holder: '',
                        account_number: '',
                        ifsc_code: '',
                        upi_id: ''
                    },

                    security: {
                        two_factor: false,
                        session_timeout: 30,
                        max_attempts: 5,
                        lockout_time: 15,
                        ip_whitelist: '',
                        rate_limit: 60
                    },

                    geofence: {
                        enabled: false,
                        lat: '',
                        lng: '',
                        radius: 500,
                        address: ''
                    },
                    geoOverride: { user_id: '', lat: '', lng: '', radius: '' },
                    geoUserList: [],
                    
                    social_media: {
                        facebook: { url: '', visible: false },
                        twitter: { url: '', visible: false },
                        instagram: { url: '', visible: false },
                        linkedin: { url: '', visible: false },
                        whatsapp: { url: '', visible: false },
                        telegram: { url: '', visible: false },
                        whatsapp_channel: { url: '', visible: false }
                    },

                    dataManage: {
                        exportTable: 'workers',
                        importTable: 'workers',
                        file: null
                    },
                    
                    homepage: {
                        stats: [],
                        why_choose_us: [],
                        service_categories: []
                    },
                    
                    userForm: {
                        username: '',
                        full_name: '',
                        email: '',
                        password: '',
                        role: 'staff',
                        department: '',
                        reporting_head: '',
                        phone: '',
                        care_permission: 0
                    },
                    
                    teamForm: {
                        name: '',
                        position: '',
                        bio: '',
                        photo_url: '',
                        display_order: 0
                    },
                    
                    templateForm: {
                        name: '',
                        content: '',
                        css: '',
                        is_default: false
                    },
                    
                    reportingHeads: [],

                    init() {
                        this.loadSettings();
                        this.loadHomepage();
                        this.loadTeamMembers();
                        this.loadUsers();
                        this.loadSocial();
                        this.loadTemplates();
                        this.loadReportingHeads();
                        this.loadAuditLogs();
                        this.loadGeofence();
                        this.loadUsersForGeo();
                    },

                    loadSettings() {
                        fetch('api/settings.php')
                            .then(res => res.json())
                            .then(data => {
                                this.settings = data;
                            });
                    },

                    loadTeamMembers() {
                        fetch('api/team.php')
                            .then(res => res.json())
                            .then(data => {
                                this.teamMembers = data;
                            });
                    },

                    loadUsers() {
                        fetch('api/users.php')
                            .then(res => res.text())
                            .then(data => {
                                this.usersList = data;
                            });
                    },

                    loadReportingHeads() {
                        fetch('api/users.php?reporting_heads=1')
                            .then(res => res.json())
                            .then(data => {
                                this.reportingHeads = data;
                            });
                    },

                                        loadTemplates() {
                        fetch(`api/templates.php?type=${this.templateType}`)
                            .then(res => res.text())
                            .then(data => {
                                this.templatesList = data;
                            });
                    },

                    editTemplate(id) {
                        fetch(`api/templates.php?id=${id}`)
                            .then(res => res.json())
                            .then(data => {
                                this.templateForm = data;
                                this.showTemplateModal = true;
                            });
                    },

                    deleteTemplate(id) {
                        if (confirm('Are you sure you want to delete this template?')) {
                            fetch(`api/templates.php?id=${id}`, {
                                method: 'DELETE'
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.loadTemplates();
                                    window.appNotify('Template deleted');
                                }
                            });
                        }
                    },

                    saveTemplate() {
                        fetch('api/templates.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                type: this.templateType,
                                ...this.templateForm
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showTemplateModal = false;
                                this.loadTemplates();
                                window.appNotify('Template saved');
                            }
                        });
                    },

                    previewTemplate() {
                        // Open preview in new window
                        const previewWindow = window.open('', '_blank');
                        previewWindow.document.write(`
                            <html>
                                <head>
                                    <style>${this.templateForm.css || ''}</style>
                                </head>
                                <body>
                                    ${this.templateForm.content || ''}
                                </body>
                            </html>
                        `);
                    },

                    loadAuditLogs() {
                        const params = new URLSearchParams({
                            page: this.currentPage,
                            ...this.auditFilters
                        });
                        fetch(`api/audit.php?${params}`)
                            .then(res => res.json())
                            .then(data => {
                                this.auditLogsList = data.html;
                                this.totalPages = data.total_pages;
                            });
                    },

                    loadGeofence() {
                        fetch('api/geofence.php?action=global')
                            .then(r => r.json())
                            .then(data => {
                                this.geofence = {
                                    enabled: data.geofence_enabled === '1',
                                    lat: data.geofence_lat || '',
                                    lng: data.geofence_lng || '',
                                    radius: data.geofence_radius || 500,
                                    address: data.geofence_address || ''
                                };
                            }).catch(() => {});
                    },

                    saveGeofence() {
                        fetch('api/geofence.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ action: 'save_global', ...this.geofence })
                        }).then(r => r.json()).then(d => {
                            if (d.success) alert('Geofencing settings saved.');
                        });
                    },

                    detectLocation() {
                        if (!navigator.geolocation) { alert('Geolocation not supported by your browser.'); return; }
                        navigator.geolocation.getCurrentPosition(pos => {
                            this.geofence.lat = pos.coords.latitude.toFixed(6);
                            this.geofence.lng = pos.coords.longitude.toFixed(6);
                            alert(`Location detected: ${this.geofence.lat}, ${this.geofence.lng}`);
                        }, () => alert('Could not get your location. Make sure location access is enabled.'));
                    },

                    loadUsersForGeo() {
                        fetch('api/users.php?list=1')
                            .then(r => r.json())
                            .then(data => { this.geoUserList = data; })
                            .catch(() => {});
                    },

                    loadUserGeoOverride() {
                        if (!this.geoOverride.user_id) return;
                        fetch(`api/geofence.php?action=user_override&user_id=${this.geoOverride.user_id}`)
                            .then(r => r.json())
                            .then(d => {
                                this.geoOverride.lat    = d.geo_override_lat || '';
                                this.geoOverride.lng    = d.geo_override_lng || '';
                                this.geoOverride.radius = d.geo_override_radius || '';
                            });
                    },

                    saveUserGeoOverride() {
                        fetch('api/geofence.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ action: 'save_user_override', ...this.geoOverride })
                        }).then(r => r.json()).then(d => {
                            if (d.success) alert('Per-user override saved.');
                        });
                    },

                    clearUserGeoOverride() {
                        fetch('api/geofence.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ action: 'clear_user_override', user_id: this.geoOverride.user_id })
                        }).then(r => r.json()).then(d => {
                            if (d.success) {
                                this.geoOverride.lat = '';
                                this.geoOverride.lng = '';
                                this.geoOverride.radius = '';
                                alert('Override cleared — user will use global geofence.');
                            }
                        });
                    },

                    saveGeneral() {
                        fetch('api/settings.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'general', data: this.settings })
                        })
                        .then(() => {
                            window.appNotify('Settings saved');
                        });
                    },

                    saveContact() {
                        fetch('api/settings.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'contact', data: this.settings })
                        })
                        .then(() => {
                            window.appNotify('Contact info saved');
                        });
                    },

                                        loadSocial() {
                        fetch('api/settings.php?section=social')
                            .then(res => res.json())
                            .then(data => {
                                try {
                                    const socialKeys = ['facebook', 'twitter', 'instagram', 'linkedin', 'whatsapp', 'telegram', 'whatsapp_channel'];
                                    socialKeys.forEach(key => {
                                        if (this.social_media && this.social_media[key]) {
                                            this.social_media[key].url = data['social_' + key] || '';
                                            this.social_media[key].visible = data['social_' + key + '_visible'] === '1';
                                        }
                                    });
                                } catch (e) {
                                    console.error('Error parsing social media data', e);
                                }
                            }).catch(err => console.error('Failed to load social settings', err));
                    },

                    saveSocial() {
                        let data = {};
                        for (let key in this.social_media) {
                            data['social_' + key] = this.social_media[key].url;
                            data['social_' + key + '_visible'] = this.social_media[key].visible ? '1' : '0';
                        }
                        fetch('api/settings.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'social', data: data })
                        })
                        .then(() => {
                            window.appNotify('Social media settings saved');
                        });
                    },

                    uploadLogo(e) {
                        const file = e.target.files[0];
                        const formData = new FormData();
                        formData.append('logo', file);

                        fetch('api/upload.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            this.settings.site_logo = data.url;
                        });
                    },

                    uploadFavicon(e) {
                        const file = e.target.files[0];
                        const formData = new FormData();
                        formData.append('favicon', file);

                        fetch('api/upload.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            this.settings.site_favicon = data.url;
                        });
                    },

                    uploadTeamPhoto(e) {
                        const file = e.target.files[0];
                        const formData = new FormData();
                        formData.append('photo', file);

                        fetch('api/upload.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            this.teamForm.photo_url = data.url;
                        });
                    },

                    saveTeamMember() {
                        fetch('api/team.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.teamForm)
                        })
                        .then(() => {
                            this.showAddTeam = false;
                            this.loadTeamMembers();
                            window.appNotify('Team member added');
                        });
                    },

                    deleteTeamMember(id) {
                        if (confirm('Are you sure you want to delete this team member?')) {
                            fetch(`api/team.php?id=${id}`, {
                                method: 'DELETE'
                            })
                            .then(() => {
                                this.loadTeamMembers();
                                window.appNotify('Team member deleted');
                            });
                        }
                    },


                    saveTemplate() {
                        fetch('api/templates.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                type: this.templateType,
                                ...this.templateForm
                            })
                        })
                        .then(() => {
                            this.showTemplateModal = false;
                            this.loadTemplates();
                            window.appNotify('Template saved');
                        });
                    },

                    exportData(format) {
                        window.location.href = `api/data_manage.php?action=export_${format}&table=${this.dataManage.exportTable}`;
                    },

                    importData() {
                        if (!this.dataManage.file) return alert('Please select a file');
                        const formData = new FormData();
                        formData.append('file', this.dataManage.file);
                        this.loading = true;
                        fetch(`api/data_manage.php?action=import_csv&table=${this.dataManage.importTable}`, {
                            method: 'POST',
                            body: formData
                        }).then(res => res.json()).then(data => {
                            this.loading = false;
                            if (data.success) window.appNotify(`Imported ${data.count} records`);
                            else alert(data.error);
                        });
                    },

                    loadHomepage() {
                        fetch('api/settings.php').then(res => res.json()).then(data => {
                            try {
                                if (data.homepage_data) {
                                    this.homepage = JSON.parse(data.homepage_data);
                                    if (this.homepage && this.homepage.service_categories) {
                                        this.homepage.service_categories.forEach(cat => {
                                            if (cat.services) {
                                                cat.services_text = cat.services.join(', ');
                                            }
                                        });
                                    } else {
                                        this.homepage = { stats: [], why_choose_us: [], service_categories: [] };
                                    }
                                }
                            } catch (e) {
                                console.error('Failed to parse homepage data', e);
                                this.homepage = { stats: [], why_choose_us: [], service_categories: [] };
                            }
                        }).catch(err => console.error('Failed to load homepage', err));
                    },

                    saveHomepage() {
                        fetch('api/settings.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'homepage', data: { homepage_data: JSON.stringify(this.homepage) } })
                        }).then(() => window.appNotify('Homepage settings saved'));
                    },

                    saveEmail() {
                        fetch('api/settings.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'email', data: this.email })
                        })
                        .then(() => {
                            window.appNotify('Email configuration saved');
                        });
                    },

                    testEmail() {
                        fetch('api/test-email.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.email)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                window.appNotify('Email test successful');
                            } else {
                                window.appNotify('Email test failed', 'error');
                            }
                        });
                    },

                    savePayment() {
                        fetch('api/settings.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'payment', data: this.payment })
                        })
                        .then(() => {
                            window.appNotify('Payment information saved');
                        });
                    },

                    saveSecurity() {
                        fetch('api/settings.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'security', data: this.security })
                        })
                        .then(() => {
                            window.appNotify('Security settings saved');
                        });
                    },

                    prevPage() {
                        if (this.currentPage > 1) {
                            this.currentPage--;
                            this.loadAuditLogs();
                        }
                    },

                    nextPage() {
                        if (this.currentPage < this.totalPages) {
                            this.currentPage++;
                            this.loadAuditLogs();
                        }
                    },

                    exportAuditLogs() {
                        const params = new URLSearchParams(this.auditFilters);
                        window.location.href = `api/audit.php?export=1&${params}`;
                    }
                }
            }
        </script>
        <?php
    }

    private function renderLogin() {
        ?>
        <div class="min-h-screen flex items-center justify-center bg-gray-100">
            <div class="bg-white p-8 rounded-lg shadow-lg w-96">
                <div class="text-center mb-6">
                    <?php if (filter_var($this->settings['site_logo'] ?? '', FILTER_VALIDATE_URL)): ?>
                        <img src="<?php echo $this->settings['site_logo']; ?>" alt="Logo" class="h-16 mx-auto mb-4">
                    <?php else: ?>
                        <div class="text-4xl mb-4"><?php echo $this->settings['site_logo'] ?? '🏢'; ?></div>
                    <?php endif; ?>
                    <h1 class="text-2xl font-bold">Admin Login</h1>
                    <p class="text-sm text-gray-600"><?php echo $this->settings['site_title'] ?? 'D K Associates'; ?></p>
                </div>

                <?php if (isset($_GET['error'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    Invalid credentials
                </div>
                <?php endif; ?>

                <?php if (isset($_GET['locked'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    Account locked due to multiple failed attempts. Please try again after 15 minutes.
                </div>
                <?php endif; ?>

                <form method="POST" action="api/login.php">
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-2">Username</label>
                        <input type="text" name="username"
                               class="w-full border rounded px-3 py-2 focus:outline-none focus:border-blue-600" required>
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium mb-2">Password</label>
                        <input type="password" name="password"
                               class="w-full border rounded px-3 py-2 focus:outline-none focus:border-blue-600" required>
                    </div>

                    <div class="mb-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="remember" class="mr-2">
                            <span class="text-sm">Remember me</span>
                        </label>
                    </div>

                    <input type="hidden" name="device_id" id="deviceIdInput">
                    <button type="submit"
                            class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700 transition">
                        Login
                    </button>
                </form>

                <div class="mt-4 text-center text-sm text-gray-600">
                    <a href="#" class="hover:text-blue-600">Forgot Password?</a>
                </div>
            </div>
        </div>

        <script>
            // Generate or retrieve device ID
            let deviceId = localStorage.getItem('admin_device_id');
            if (!deviceId) {
                deviceId = 'admin_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
                localStorage.setItem('admin_device_id', deviceId);
            }
            document.getElementById('deviceIdInput').value = deviceId;
        </script>
        <?php
    }

    // Helper methods
    private function getUnreadCount() {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bindValue(1, $_SESSION['admin_id']);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row['count'] ?? 0;
    }
    
    private function generateEmployeeCode($id) {
        $year = date('y');
        $month = strtoupper(date('M', strtotime('2026-' . date('m') . '-01')));
        $hexCode = str_pad(dechex($id), 3, '0', STR_PAD_LEFT);
        return 'DKA' . $year . $month . $hexCode;
    }

    private function generateWorkerCode($id) {
        $year = date('y');
        $month = strtoupper(date('M', strtotime('2026-' . date('m') . '-01')));
        $hexCode = str_pad(dechex($id), 3, '0', STR_PAD_LEFT);
        return 'DKW' . $year . $month . $hexCode;
    }

    private function getTaskCounts() {
        $result = $this->db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM tasks");
        return $result->fetchArray(SQLITE3_ASSOC);
    }

    private function getApplicationCounts() {
        $result = $this->db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as pending
            FROM applications");
        return $result->fetchArray(SQLITE3_ASSOC);
    }

    private function getEnquiryCounts() {
        $result = $this->db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM enquiries");
        return $result->fetchArray(SQLITE3_ASSOC);
    }

    private function getWorkerCounts() {
        $result = $this->db->query("SELECT COUNT(*) as total FROM workers WHERE status = 'active'");
        return $result->fetchArray(SQLITE3_ASSOC);
    }
}

// Initialize and render admin panel
$admin = new AdminPanel();
$admin->render();
?>