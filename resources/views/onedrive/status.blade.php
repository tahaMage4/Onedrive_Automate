<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OneDrive Connection Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>
    <div class="container mt-5">
        <h1><i class="fab fa-microsoft"></i> OneDrive Connection Status</h1>

        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <!-- Connection Status Card -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-link"></i> Connection Status</h5>
            </div>
            <div class="card-body">
                @if ($isConnected)
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <strong>Connected to OneDrive</strong>
                        @if ($hasRefreshToken)
                            <p class="mb-0 mt-2">Refresh token is available for automatic reconnection.</p>
                        @endif
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <form action="{{ route('onedrive.logout') }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-sign-out-alt"></i> Disconnect
                            </button>
                        </form>

                        <button type="button" class="btn btn-info" onclick="testConnection()">
                            <i class="fas fa-wifi"></i> Test Connection
                        </button>

                        <button type="button" class="btn btn-secondary" onclick="loadSyncStats()">
                            <i class="fas fa-chart-bar"></i> Sync Stats
                        </button>
                    </div>
                @else
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Not connected to OneDrive</strong>
                    </div>

                    <a href="{{ route('onedrive.login') }}" class="btn btn-primary">
                        <i class="fab fa-microsoft"></i> Connect to OneDrive
                    </a>
                @endif
            </div>
        </div>

        @if ($isConnected)
            <!-- Sync Status Card -->
            @if (!empty($syncStatus))
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-sync-alt"></i> Last Sync Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            @if (isset($syncStatus['mod_last_sync']))
                                <div class="col-md-6">
                                    <h6><i class="fas fa-folder"></i> MOD Files</h6>
                                    <p class="mb-1"><strong>Last Sync:</strong> {{ $syncStatus['mod_last_sync'] ? \Carbon\Carbon::parse($syncStatus['mod_last_sync'])->diffForHumans() : 'Never' }}</p>
                                    <p class="mb-1"><strong>Files:</strong> {{ $syncStatus['mod_file_count'] ?? 0 }} files</p>
                                    <p class="mb-0"><strong>Type:</strong> {{ $syncStatus['mod_sync_type'] ?? 'Unknown' }}</p>
                                </div>
                            @endif

                            @if (isset($syncStatus['ori_last_sync']))
                                <div class="col-md-6">
                                    <h6><i class="fas fa-folder"></i> ORI Files</h6>
                                    <p class="mb-1"><strong>Last Sync:</strong> {{ $syncStatus['ori_last_sync'] ? \Carbon\Carbon::parse($syncStatus['ori_last_sync'])->diffForHumans() : 'Never' }}</p>
                                    <p class="mb-1"><strong>Files:</strong> {{ $syncStatus['ori_file_count'] ?? 0 }} files</p>
                                    <p class="mb-0"><strong>Type:</strong> {{ $syncStatus['ori_sync_type'] ?? 'Unknown' }}</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <!-- Flash Folders Status Card -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-folder"></i> Flash Folders Status</h5>
                    <button type="button" class="btn btn-light btn-sm" onclick="refreshFolderData()">
                        <i class="fas fa-refresh"></i> Refresh
                    </button>
                </div>
                <div class="card-body">
                    <div class="row" id="folderStatusContainer">
                        @foreach ($flashFolderData as $folderName => $folderData)
                            <div class="col-md-6 mb-3">
                                <div class="card h-100">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0">{{ $folderName }}</h6>
                                        @if (isset($folderData['error']))
                                            <span class="badge bg-danger">Error</span>
                                        @else
                                            @php
                                                $fileCount = isset($folderData['files']) ? count($folderData['files']) : ($folderData['count'] ?? 0);
                                            @endphp
                                            <span class="badge bg-success">{{ $fileCount }} files</span>
                                        @endif
                                    </div>
                                    <div class="card-body">
                                        @if (isset($folderData['error']))
                                            <div class="text-danger">
                                                <i class="fas fa-exclamation-circle"></i>
                                                {{ $folderData['error'] }}
                                            </div>
                                        @else
                                            @php
                                                $files = $folderData['files'] ?? [];
                                                $fileCount = count($files);
                                                $totalSize = $folderData['total_size'] ?? array_sum(array_column($files, 'size'));
                                            @endphp
                                            <div class="small">
                                                <p class="mb-1"><i class="fas fa-file"></i> {{ $fileCount }} .fls files</p>
                                                @if ($totalSize > 0)
                                                    <p class="mb-2">
                                                        <i class="fas fa-weight-hanging"></i>
                                                        @if ($totalSize > 1048576)
                                                            {{ round($totalSize / 1048576, 2) }} MB
                                                        @elseif ($totalSize > 1024)
                                                            {{ round($totalSize / 1024, 2) }} KB
                                                        @else
                                                            {{ $totalSize }} bytes
                                                        @endif
                                                    </p>
                                                @endif
                                                <div class="d-flex gap-1">
                                                    <button type="button" class="btn btn-primary btn-sm" onclick="previewFolder('{{ str_replace(' Flash', '', $folderName) }}')">
                                                        <i class="fas fa-eye"></i> Preview
                                                    </button>
                                                    <button type="button" class="btn btn-success btn-sm" onclick="downloadFolderZip('{{ str_replace(' Flash', '', $folderName) }}')">
                                                        <i class="fas fa-download"></i> ZIP
                                                    </button>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Enhanced Sync Files Card -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-sync"></i> Sync Files</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('onedrive.sync') }}" method="POST" id="syncForm">
                        @csrf
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="option" class="form-label">Sync Method</label>
                                    <select class="form-select" id="option" name="option">
                                        <option value="zip_delta">ZIP Delta Sync (Recommended)</option>
                                        <option value="individual_files">Individual Files (Fallback)</option>
                                    </select>
                                    <div class="form-text">ZIP Delta sync is faster and more efficient</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Sync Options</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="force_full_sync" name="force_full_sync">
                                        <label class="form-check-label" for="force_full_sync">
                                            Force Full Sync
                                        </label>
                                        <div class="form-text small">Ignore delta tokens and download all files</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-success btn-lg" id="syncBtn">
                                <i class="fas fa-download"></i> <span id="syncBtnText">Sync Files Now</span>
                            </button>

                            <button type="button" class="btn btn-warning" onclick="forceFullSync()">
                                <i class="fas fa-redo"></i> Force Full Sync
                            </button>

                            <button type="button" class="btn btn-info" onclick="getSyncReport()">
                                <i class="fas fa-file-alt"></i> Sync Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Flash Folder Contents -->
            @if (!empty($flashFolderData))
                @foreach ($flashFolderData as $folderName => $folderData)
                    @if (!isset($folderData['error']) && !empty($folderData['files'] ?? []))
                        <div class="card mb-4">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-folder-open"></i> {{ $folderName }} Contents
                                    <small class="ms-2">({{ count($folderData['files']) }} files)</small>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th><i class="fas fa-file"></i> Name</th>
                                                <th><i class="fas fa-weight-hanging"></i> Size</th>
                                                <th><i class="fas fa-clock"></i> Last Modified</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse ($folderData['files'] as $file)
                                                <tr>
                                                    <td>
                                                        <i class="fas fa-file text-primary"></i>
                                                        {{ $file['name'] }}
                                                    </td>
                                                    <td>
                                                        @if (isset($file['size']))
                                                            @if ($file['size'] > 1048576)
                                                                {{ round($file['size'] / 1048576, 2) }} MB
                                                            @elseif ($file['size'] > 1024)
                                                                {{ round($file['size'] / 1024, 2) }} KB
                                                            @else
                                                                {{ $file['size'] }} bytes
                                                            @endif
                                                        @else
                                                            -
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if (isset($file['lastModified']))
                                                            {{ \Carbon\Carbon::parse($file['lastModified'])->format('M d, Y H:i') }}
                                                        @else
                                                            -
                                                        @endif
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="3" class="text-center text-muted">
                                                        <i class="fas fa-folder-open"></i> No .fls files found
                                                    </td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            @endif
        @endif

        <!-- Help Card -->
        <div class="card">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-question-circle"></i> Help & Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <h6><i class="fas fa-info-circle text-info"></i> Sync Methods</h6>
                        <ul class="small">
                            <li><strong>ZIP Delta:</strong> Fast, incremental sync using delta tokens</li>
                            <li><strong>Individual Files:</strong> Downloads files one by one (slower)</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-cog text-secondary"></i> File Types</h6>
                        <ul class="small">
                            <li>Flash files (.fls extension only)</li>
                            <li>Stored in separate MOD and ORI folders</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-folder text-warning"></i> Storage Locations</h6>
                        <ul class="small">
                            <li><strong>MOD:</strong> storage/app/mod-files</li>
                            <li><strong>ORI:</strong> storage/app/ori-files</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Folder Preview Modal -->
    <div class="modal fade" id="folderPreviewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Folder Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="folderPreviewContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sync Report Modal -->
    <div class="modal fade" id="syncReportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sync Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="syncReportContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Set CSRF token for AJAX requests
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        // Test connection function
        function testConnection() {
            fetch('/test', {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showAlert('success', `Connection successful! User: ${data.user} (${data.email})`);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                showAlert('error', 'Connection test failed: ' + error.message);
            });
        }

        // Load sync statistics
        function loadSyncStats() {
            fetch('/onedrive/sync-stats', {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    const stats = data.data;
                    let message = 'Sync Statistics:\n';
                    message += `MOD Files: ${stats.mod_file_count || 0} (Last sync: ${stats.mod_last_sync || 'Never'})\n`;
                    message += `ORI Files: ${stats.ori_file_count || 0} (Last sync: ${stats.ori_last_sync || 'Never'})`;
                    alert(message);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                showAlert('error', 'Failed to load sync stats: ' + error.message);
            });
        }

        // Preview folder contents
        function previewFolder(folderName) {
            const modal = new bootstrap.Modal(document.getElementById('folderPreviewModal'));
            const content = document.getElementById('folderPreviewContent');

            content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div></div>';
            modal.show();

            fetch('/onedrive/folder-preview', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ folder: folderName })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let html = `<h6>${folderName} Folder (${data.count} files)</h6>`;
                    html += '<div class="table-responsive"><table class="table table-sm">';
                    html += '<thead><tr><th>Name</th><th>Size</th></tr></thead><tbody>';

                    data.files.forEach(file => {
                        const size = file.size > 1048576
                            ? (file.size / 1048576).toFixed(2) + ' MB'
                            : file.size > 1024
                                ? (file.size / 1024).toFixed(2) + ' KB'
                                : file.size + ' bytes';
                        html += `<tr><td>${file.name}</td><td>${size}</td></tr>`;
                    });

                    html += '</tbody></table></div>';
                    content.innerHTML = html;
                } else {
                    content.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
                }
            })
            .catch(error => {
                content.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
            });
        }

        // Download folder as ZIP
        function downloadFolderZip(folderName) {
            showAlert('info', 'Preparing ZIP download...');

            fetch('/onedrive/download-folder-zip', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ folder: folderName })
            })
            .then(response => {
                if (response.ok) {
                    return response.blob();
                } else {
                    return response.json().then(data => {
                        throw new Error(data.error || 'Download failed');
                    });
                }
            })
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `${folderName}_files.zip`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                showAlert('success', 'ZIP download started!');
            })
            .catch(error => {
                showAlert('error', 'Download failed: ' + error.message);
            });
        }

        // Force full sync
        function forceFullSync() {
            if (confirm('This will reset delta tokens and download all files. Continue?')) {
                window.location.href = '/onedrive/force-full-sync';
            }
        }

        // Get sync report
        function getSyncReport() {
            const modal = new bootstrap.Modal(document.getElementById('syncReportModal'));
            const content = document.getElementById('syncReportContent');

            content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div></div>';
            modal.show();

            fetch('/onedrive/sync-report', {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    const report = data.data;
                    let html = '<div class="row">';

                    // Remote stats
                    html += '<div class="col-md-6"><h6>Remote (OneDrive)</h6>';
                    html += `<p><strong>MOD Files:</strong> ${report.mod_file_count || 0}</p>`;
                    html += `<p><strong>ORI Files:</strong> ${report.ori_file_count || 0}</p>`;
                    html += `<p><strong>Last MOD Sync:</strong> ${report.mod_last_sync || 'Never'}</p>`;
                    html += `<p><strong>Last ORI Sync:</strong> ${report.ori_last_sync || 'Never'}</p></div>`;

                    // Local stats
                    html += '<div class="col-md-6"><h6>Local Storage</h6>';
                    html += `<p><strong>MOD Files:</strong> ${report.mod_local_files || 0}</p>`;
                    html += `<p><strong>ORI Files:</strong> ${report.ori_local_files || 0}</p>`;
                    html += `<p><strong>MOD Path:</strong> ${report.local_storage_paths?.MOD || 'N/A'}</p>`;
                    html += `<p><strong>ORI Path:</strong> ${report.local_storage_paths?.ORI || 'N/A'}</p></div>`;

                    html += '</div>';
                    content.innerHTML = html;
                } else {
                    content.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                }
            })
            .catch(error => {
                content.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
            });
        }

        // Refresh folder data
        function refreshFolderData() {
            location.reload();
        }

        // Show alert function
        function showAlert(type, message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            const container = document.querySelector('.container');
            container.insertBefore(alertDiv, container.children[1]);

            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Form submission with loading state
        document.getElementById('syncForm').addEventListener('submit', function() {
            const btn = document.getElementById('syncBtn');
            const btnText = document.getElementById('syncBtnText');

            btn.disabled = true;
            btnText.textContent = 'Syncing...';
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + btnText.textContent;
        });
    </script>
</body>
</html>