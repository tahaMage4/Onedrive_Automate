<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OneDrive Connection Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .progress-container {
            margin-top: 20px;
            display: none;
        }
        .file-processing-log {
            max-height: 300px;
            overflow-y: auto;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
            font-family: monospace;
        }
        .log-entry {
            margin-bottom: 5px;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
        }
        .log-success {
            color: #28a745;
        }
        .log-error {
            color: #dc3545;
        }
        .log-warning {
            color: #ffc107;
        }
        .log-info {
            color: #17a2b8;
        }
    </style>
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

                    <form action="{{ route('onedrive.logout') }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-sign-out-alt"></i> Disconnect
                        </button>
                    </form>
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
            <!-- Local Files Summary Card -->
            <div class="card mb-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="fas fa-hdd"></i> Local Files Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">Storage Path</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-0">
                                        <i class="fas fa-folder-open"></i> storage/app/flashfiles
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">Summary</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1">
                                        <i class="fas fa-file"></i> Total Files: {{ $localFileSummary['total_files'] ?? 0 }}
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-database"></i> Total Size:
                                        @if(isset($localFileSummary['total_size']))
                                            @if($localFileSummary['total_size'] > 1048576)
                                                {{ round($localFileSummary['total_size'] / 1048576, 2) }} MB
                                            @elseif($localFileSummary['total_size'] > 1024)
                                                {{ round($localFileSummary['total_size'] / 1024, 2) }} KB
                                            @else
                                                {{ $localFileSummary['total_size'] }} bytes
                                            @endif
                                        @else
                                            0 bytes
                                        @endif
                                    </p>
                                    @if(isset($localFileSummary['last_sync']))
                                        <p class="mb-0">
                                            <i class="fas fa-clock"></i> Last Sync:
                                            {{ \Carbon\Carbon::parse($localFileSummary['last_sync'])->format('M d, Y H:i') }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Flash Folders Status Card -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-folder"></i> Flash Folders Status</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach ($flashFolderData as $folderName => $folderData)
                            <div class="col-md-6 mb-3">
                                <div class="card h-100">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0">{{ $folderName }}</h6>
                                        @if (isset($folderData['error']))
                                            <span class="badge bg-danger">Error</span>
                                        @else
                                            <span class="badge bg-success">{{ count($folderData) }} items</span>
                                        @endif
                                    </div>
                                    <div class="card-body">
                                        @if (isset($folderData['error']))
                                            <div class="text-danger">
                                                <i class="fas fa-exclamation-circle"></i>
                                                {{ $folderData['error'] }}
                                            </div>
                                        @else
                                            <div class="small">
                                                @php
                                                    $fileCount = collect($folderData)->where('type', 'file')->count();
                                                    $folderCount = collect($folderData)->where('type', 'folder')->count();
                                                @endphp
                                                <i class="fas fa-file"></i> {{ $fileCount }} files<br>
                                                <i class="fas fa-folder"></i> {{ $folderCount }} folders
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Sync Files Card -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-sync"></i> Sync Files</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('onedrive.sync') }}" method="POST">
                        @csrf
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="option" class="form-label">Sync Option</label>
                                    <select class="form-select" id="option" name="option">
                                        <option value="flashfiles">Flash Files Only (.fls files)</option>
                                        <option value="folders">All Flash Folders</option>
                                    </select>
                                    <div class="form-text">Choose what to sync from OneDrive</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="local_path" class="form-label">Local Storage Path</label>
                                    <input type="text" class="form-control" id="local_path" name="local_path" value="flashfiles" placeholder="e.g., flashfiles">
                                    <div class="form-text">Relative to storage/app directory</div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-download"></i> Sync Files Now
                        </button>
                    </form>
                </div>
            </div>

            <!-- Process Flash Files Card -->
            <div class="card mb-4">
                <div class="card-header bg-warning text-white">
                    <h5 class="mb-0"><i class="fas fa-cogs"></i> Process Flash Files</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> This will process downloaded flash files into products in your database.
                    </div>

                    <form id="processForm" action="{{ route('onedrive.process-flash') }}" method="POST">
                        @csrf
                        <div class="mb-3">
                            <label for="process_path" class="form-label">Local Path to Process</label>
                            <input type="text" class="form-control" id="process_path" name="local_path" value="flashfiles" placeholder="e.g., flashfiles">
                            <div class="form-text">Relative to storage/app directory</div>
                        </div>

                        <button type="submit" class="btn btn-warning btn-lg" id="processButton">
                            <i class="fas fa-cog"></i> Process Flash Files
                        </button>
                    </form>

                    <div id="progressContainer" class="progress-container">
                        <div class="progress mb-3">
                            <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                        </div>
                        <div id="progressText" class="text-center mb-3">Preparing to process files...</div>
                        <div id="fileProcessingLog" class="file-processing-log"></div>
                    </div>
                </div>
            </div>

            <!-- Flash Folder Contents -->
            @if (!empty($flashFolderData))
                @foreach ($flashFolderData as $folderName => $folderData)
                    @if (!isset($folderData['error']) && !empty($folderData))
                        <div class="card mb-4">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-folder-open"></i> {{ $folderName }} Contents
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>S.No</th>
                                                <th><i class="fas fa-file"></i> Name</th>
                                                <th><i class="fas fa-tag"></i> Type</th>
                                                <th><i class="fas fa-weight-hanging"></i> Size</th>
                                                <th><i class="fas fa-clock"></i> Last Modified</th>
                                                <th><i class="fas fa-check-circle"></i> Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse ($folderData as $index => $file)
                                                @php
                                                    // Determine if file exists locally
                                                    $subfolder = strpos($folderName, 'MOD') !== false ? 'MOD' : 'ORI';
                                                    $localPath = 'flashfiles/' . $subfolder . '/' . ($file['name'] ?? '');
                                                    $fileExists = Storage::exists($localPath);
                                                    $sizeMatches = $fileExists &&
                                                                  ($file['type'] === 'file') &&
                                                                  Storage::size($localPath) === ($file['size'] ?? 0);
                                                @endphp
                                                <tr>
                                                    <td>{{ $index + 1 }}</td>
                                                    <td>
                                                        @if ($file['type'] === 'file')
                                                            <i class="fas fa-file text-primary"></i>
                                                        @else
                                                            <i class="fas fa-folder text-warning"></i>
                                                        @endif
                                                        {{ $file['name'] }}
                                                    </td>
                                                    <td>
                                                        @if ($file['type'] === 'file')
                                                            <span class="badge bg-primary">File</span>
                                                        @else
                                                            <span class="badge bg-warning">Folder</span>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if ($file['type'] === 'file' && isset($file['size']))
                                                            @if ($file['size'] > 1048576)
                                                                {{ round($file['size'] / 1048576, 2) }} MB
                                                            @elseif ($file['size'] > 1024)
                                                                {{ round($file['size'] / 1024, 2) }} KB
                                                            @else
                                                                {{ $file['size'] }} bytes
                                                            @endif
                                                        @elseif ($file['type'] === 'folder' && isset($file['childCount']))
                                                            {{ $file['childCount'] }} items
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
                                                    <td>
                                                        @if($file['type'] === 'file')
                                                            @if($fileExists && $sizeMatches)
                                                                <span class="badge bg-success">Synced</span>
                                                            @elseif($fileExists)
                                                                <span class="badge bg-warning">Size mismatch</span>
                                                            @else
                                                                <span class="badge bg-danger">Not synced</span>
                                                            @endif
                                                        @else
                                                            <span class="badge bg-info">Folder</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">
                                                        <i class="fas fa-folder-open"></i> No files found
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
                <h5 class="mb-0"><i class="fas fa-question-circle"></i> Help</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-info-circle text-info"></i> Supported URLs</h6>
                        <ul class="small">
                            <li>SharePoint sharing URLs</li>
                            <li>OneDrive for Business folders</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-cog text-secondary"></i> File Types</h6>
                        <ul class="small">
                            <li>Flash files (.fls extension)</li>
                            <li>All file types in selected folders</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const processForm = document.getElementById('processForm');
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const fileProcessingLog = document.getElementById('fileProcessingLog');
            const processButton = document.getElementById('processButton');

            if (processForm) {
                processForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    // Show progress container
                    progressContainer.style.display = 'block';
                    processButton.disabled = true;
                    processButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

                    // Create an EventSource connection to listen for progress updates
                    const eventSource = new EventSource("{{ route('onedrive.process-flash') }}?local_path=" +
                        encodeURIComponent(document.getElementById('process_path').value));

                    eventSource.onmessage = function(event) {
                        const data = JSON.parse(event.data);

                        if (data.progress) {
                            progressBar.style.width = data.progress + '%';
                            progressText.textContent = data.message;

                            // Add log entry
                            const logEntry = document.createElement('div');
                            logEntry.className = 'log-entry';

                            if (data.type === 'success') {
                                logEntry.classList.add('log-success');
                                logEntry.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                            } else if (data.type === 'error') {
                                logEntry.classList.add('log-error');
                                logEntry.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                            } else if (data.type === 'warning') {
                                logEntry.classList.add('log-warning');
                                logEntry.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + data.message;
                            } else {
                                logEntry.classList.add('log-info');
                                logEntry.innerHTML = '<i class="fas fa-info-circle"></i> ' + data.message;
                            }

                            fileProcessingLog.appendChild(logEntry);
                            fileProcessingLog.scrollTop = fileProcessingLog.scrollHeight;
                        }

                        if (data.complete) {
                            progressBar.style.width = '100%';
                            progressText.textContent = data.message;
                            eventSource.close();
                            processButton.disabled = false;
                            processButton.innerHTML = '<i class="fas fa-cog"></i> Process Complete';

                            // Show completion message
                            const logEntry = document.createElement('div');
                            logEntry.className = 'log-entry log-success';
                            logEntry.innerHTML = '<i class="fas fa-check-circle"></i> Processing complete!';
                            fileProcessingLog.appendChild(logEntry);
                            fileProcessingLog.scrollTop = fileProcessingLog.scrollHeight;

                            // Reload the page after 3 seconds to show updated status
                            setTimeout(function() {
                                window.location.reload();
                            }, 3000);
                        }
                    };

                    eventSource.onerror = function() {
                        progressText.textContent = 'Connection error occurred';
                        processButton.disabled = false;
                        processButton.innerHTML = '<i class="fas fa-cog"></i> Try Again';
                        eventSource.close();
                    };
                });
            }
        });
    </script>
</body>
</html>