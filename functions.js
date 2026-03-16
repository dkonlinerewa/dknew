
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
            setInterval(() => {
                fetch('admin.php?action=chat&type=guest&unread=1').then(r => r.json()).then(d => {
                    if (d && d.count !== undefined) this.queueCount = parseInt(d.count);
                }).catch(() => {});
            }, 10000);
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
                            fetch('admin.php?action=notes&active=1')
                                .then(res => res.json())
                                .then(data => {
                                    this.activeNotes = data;
                                    this.noteCount = data.length;
                                });
                        },

                        addNote(content) {
                            fetch('admin.php?action=notes', {
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
                            fetch('admin.php?action=notes', {
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
        messages: [], isGuestTyping: false,
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
            this.queueCount = 0;
            let url = `admin.php?action=chat&type=${this.activeTab}`;
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
            fetch('admin.php?action=chat&type=guest_sessions')
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
            fetch('admin.php?action=chat', {
                method: 'PUT',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ session_id: sessionId })
            }).catch(err => console.error('Error marking read:', err));
        },

        terminateSession(sessionId) {
            if (confirm('Are you sure you want to terminate this chat? The guest will be notified.')) {
                fetch('admin.php?action=chat', {
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

                sendTyping() {
            if (this.activeTab === 'guest' && this.selectedSession) {
                fetch('admin.php?action=chat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'typing', session_id: this.selectedSession, sender_type: 'admin' })
                }).catch(() => {});
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

            fetch('admin.php?action=chat', {
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

            let url = `admin.php?action=chat&type=${this.activeTab}`;
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
                        fetch('admin.php?action=users&list=1').then(r => r.json()).then(d => { this.teamList = d; }).catch(() => {});
                    },
                    loadReports() {
                        let url = `admin.php?action=reports&date=${this.filterDate}`;
                        if (this.filterUser) url += `&user_id=${this.filterUser}`;
                        fetch(url).then(r => r.json()).then(d => { this.reports = Array.isArray(d) ? d : []; }).catch(() => {});
                    },
                    submitReport() {
                        fetch('admin.php?action=reports', {
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


            function vacanciesManager() {
                return {
                    vacancies: [],
                    showModal: false,
                    editMode: false,
                    form: { id: null, title: '', location: '', type: '', salary: '', description: '', requirements: '', urgent: false, is_active: true },
                    init() { this.loadVacancies(); },
                    loadVacancies() {
                        fetch('admin.php?action=vacancies&all=1')
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
                        fetch('admin.php?action=vacancies', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                            .then(r => r.json()).then(d => {
                                if (d.success) { this.showModal = false; this.resetForm(); this.loadVacancies(); }
                                else alert(d.error || 'Failed to save vacancy');
                            }).catch(() => alert('Network error'));
                    },
                    deleteVacancy(id) {
                        if (!confirm('Deactivate this vacancy?')) return;
                        fetch('admin.php?action=vacancies&id=' + id, { method: 'DELETE' })
                            .then(r => r.json()).then(d => { if (d.success) this.loadVacancies(); }).catch(() => {});
                    }
                };
            }


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
                        fetch('admin.php?action=holidays')
                            .then(res => res.text())
                            .then(data => {
                                this.holidaysList = data;
                            });
                    },

                    loadRecruitment() {
                        fetch('admin.php?action=recruitment')
                            .then(res => res.json())
                            .then(data => {
                                this.recruitment = data;
                            });
                    },

                    loadVacancies() {
                        fetch('admin.php?action=vacancies')
                            .then(res => res.text())
                            .then(data => {
                                this.vacanciesList = data;
                            });
                    },

                    loadQuotations() {
                        fetch('admin.php?action=quotations')
                            .then(res => res.text())
                            .then(data => {
                                this.quotationsList = data;
                            });
                    },

                    loadEnquiries() {
                        fetch('admin.php?action=enquiries')
                            .then(res => res.text())
                            .then(data => {
                                this.enquiriesList = data;
                            });
                    },

                                        saveVacancy() {
                        fetch('admin.php?action=vacancies', {
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
                        fetch('admin.php?action=holidays', {
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
                            fetch('admin.php?action=holidays&id=${id}&type=${type}', {
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
                        fetch('admin.php?action=holidays&action=event', {
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
                            fetch('admin.php?action=vacancies&id=${id}', {
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
                        window.open('admin.php?action=export&type=quotation&id=${id}&format=pdf', '_blank');
                    },

                    downloadQuote(id) {
                        window.location.href = 'admin.php?action=export&type=quotation&id=${id}&format=pdf&download=1';
                    },

                    duplicateQuote(id) {
                        fetch('admin.php?action=quotations&duplicate=${id}')
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
                            fetch('admin.php?action=quotations&id=${id}', {
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
                        fetch('admin.php?action=quotations', {
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
                        fetch('admin.php?action=workers')
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
                        fetch('admin.php?action=users&list=1')
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
                        const url = this.editingWorker ? `admin.php?action=workers&id=${this.editingWorker.id}` : 'api/workers.php';
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

                        fetch('admin.php?action=upload', {
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
                        fetch('admin.php?action=settings')
                            .then(res => res.json())
                            .then(data => {
                                this.settings = data;
                            });
                    },

                    loadTeamMembers() {
                        fetch('admin.php?action=team')
                            .then(res => res.json())
                            .then(data => {
                                this.teamMembers = data;
                            });
                    },

                    loadUsers() {
                        fetch('admin.php?action=users')
                            .then(res => res.text())
                            .then(data => {
                                this.usersList = data;
                            });
                    },

                    loadReportingHeads() {
                        fetch('admin.php?action=users&reporting_heads=1')
                            .then(res => res.json())
                            .then(data => {
                                this.reportingHeads = data;
                            });
                    },

                                        loadTemplates() {
                        fetch('admin.php?action=templates&type=${this.templateType}')
                            .then(res => res.text())
                            .then(data => {
                                this.templatesList = data;
                            });
                    },

                    editTemplate(id) {
                        fetch('admin.php?action=templates&id=${id}')
                            .then(res => res.json())
                            .then(data => {
                                this.templateForm = data;
                                this.showTemplateModal = true;
                            });
                    },

                    deleteTemplate(id) {
                        if (confirm('Are you sure you want to delete this template?')) {
                            fetch('admin.php?action=templates&id=${id}', {
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
                        fetch('admin.php?action=templates', {
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
                        fetch('admin.php?action=audit&${params}')
                            .then(res => res.json())
                            .then(data => {
                                this.auditLogsList = data.html;
                                this.totalPages = data.total_pages;
                            });
                    },

                    loadGeofence() {
                        fetch('admin.php?action=geofence&action=global')
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
                        fetch('admin.php?action=geofence', {
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
                        fetch('admin.php?action=users&list=1')
                            .then(r => r.json())
                            .then(data => { this.geoUserList = data; })
                            .catch(() => {});
                    },

                    loadUserGeoOverride() {
                        if (!this.geoOverride.user_id) return;
                        fetch('admin.php?action=geofence&action=user_override&user_id=${this.geoOverride.user_id}')
                            .then(r => r.json())
                            .then(d => {
                                this.geoOverride.lat    = d.geo_override_lat || '';
                                this.geoOverride.lng    = d.geo_override_lng || '';
                                this.geoOverride.radius = d.geo_override_radius || '';
                            });
                    },

                    saveUserGeoOverride() {
                        fetch('admin.php?action=geofence', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ action: 'save_user_override', ...this.geoOverride })
                        }).then(r => r.json()).then(d => {
                            if (d.success) alert('Per-user override saved.');
                        });
                    },

                    clearUserGeoOverride() {
                        fetch('admin.php?action=geofence', {
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
                        fetch('admin.php?action=settings', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'general', data: this.settings })
                        })
                        .then(() => {
                            window.appNotify('Settings saved');
                        });
                    },

                    saveContact() {
                        fetch('admin.php?action=settings', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'contact', data: this.settings })
                        })
                        .then(() => {
                            window.appNotify('Contact info saved');
                        });
                    },

                                        loadSocial() {
                        fetch('admin.php?action=settings&section=social')
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
                        fetch('admin.php?action=settings', {
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

                        fetch('admin.php?action=upload', {
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

                        fetch('admin.php?action=upload', {
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

                        fetch('admin.php?action=upload', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            this.teamForm.photo_url = data.url;
                        });
                    },

                    saveTeamMember() {
                        fetch('admin.php?action=team', {
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
                            fetch('admin.php?action=team&id=${id}', {
                                method: 'DELETE'
                            })
                            .then(() => {
                                this.loadTeamMembers();
                                window.appNotify('Team member deleted');
                            });
                        }
                    },


                    saveTemplate() {
                        fetch('admin.php?action=templates', {
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
                        window.location.href = 'admin.php?action=data_manage&action=export_${format}&table=${this.dataManage.exportTable}';
                    },

                    importData() {
                        if (!this.dataManage.file) return alert('Please select a file');
                        const formData = new FormData();
                        formData.append('file', this.dataManage.file);
                        this.loading = true;
                        fetch('admin.php?action=data_manage&action=import_csv&table=${this.dataManage.importTable}', {
                            method: 'POST',
                            body: formData
                        }).then(res => res.json()).then(data => {
                            this.loading = false;
                            if (data.success) window.appNotify(`Imported ${data.count} records`);
                            else alert(data.error);
                        });
                    },

                    loadHomepage() {
                        fetch('admin.php?action=settings').then(res => res.json()).then(data => {
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
                        fetch('admin.php?action=settings', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'homepage', data: { homepage_data: JSON.stringify(this.homepage) } })
                        }).then(() => window.appNotify('Homepage settings saved'));
                    },

                    saveEmail() {
                        fetch('admin.php?action=settings', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'email', data: this.email })
                        })
                        .then(() => {
                            window.appNotify('Email configuration saved');
                        });
                    },

                    testEmail() {
                        fetch('admin.php?action=test_email', {
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
                        fetch('admin.php?action=settings', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ section: 'payment', data: this.payment })
                        })
                        .then(() => {
                            window.appNotify('Payment information saved');
                        });
                    },

                    saveSecurity() {
                        fetch('admin.php?action=settings', {
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
                        window.location.href = 'admin.php?action=audit&export=1&${params}';
                    }
                }
            }


            // Generate or retrieve device ID
            let deviceId = localStorage.getItem('admin_device_id');
            if (!deviceId) {
                deviceId = 'admin_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
                localStorage.setItem('admin_device_id', deviceId);
            }
            document.getElementById('deviceIdInput').value = deviceId;
