<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OneDrive Connection Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
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
        .log-success { color: #28a745; }
        .log-error { color: #dc3545; }
        .log-warning { color: #ffc107; }
        .log-info { color: #17a2b8; }

        .tab-content {
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 0.375rem 0.375rem;
            padding: 1rem;
        }

        .pagination-controls {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .entries-per-page {
            display: flex;
            align-items: center;
            gap: 10px;
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

                    <form action="{{ route('onedrive.process-flash') }}" method="POST">
                        @csrf
                        <div class="mb-3">
                            <label for="process_path" class="form-label">Local Path to Process</label>
                            <input type="text" class="form-control" id="process_path" name="local_path" value="flashfiles" placeholder="e.g., flashfiles">
                            <div class="form-text">Relative to storage/app directory</div>
                        </div>

                        <button type="submit" class="btn btn-warning btn-lg">
                            <i class="fas fa-cog"></i> Process Flash Files
                        </button>
                    </form>
                </div>
            </div>

            <!-- Flash Folder Contents with Tabs -->
            @if (!empty($flashFolderData))
                <div class="card mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-folder-open"></i> Flash Folder Contents
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <!-- Tabs Navigation -->
                        <ul class="nav nav-tabs" id="flashFolderTabs" role="tablist">
                            @php $isFirst = true; @endphp
                            @foreach ($flashFolderData as $folderName => $folderData)
                                @if (!isset($folderData['error']) && !empty($folderData))
                                    @php
                                        $tabId = 'tab-' . md5($folderName);
                                        $contentId = 'content-' . md5($folderName);
                                        $displayName = (strpos($folderName, 'MOD') !== false) ? 'MOD' : 'ORIGINAL';
                                    @endphp
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link {{ $isFirst ? 'active' : '' }}"
                                                id="{{ $tabId }}"
                                                data-bs-toggle="tab"
                                                data-bs-target="#{{ $contentId }}"
                                                type="button"
                                                role="tab"
                                                aria-controls="{{ $contentId }}"
                                                aria-selected="{{ $isFirst ? 'true' : 'false' }}">
                                            <i class="fas fa-folder"></i> {{ $displayName }} Flash Contents
                                            <span class="badge bg-primary ms-2">{{ count($folderData) }}</span>
                                        </button>
                                    </li>
                                    @php $isFirst = false; @endphp
                                @endif
                            @endforeach
                        </ul>

                        <!-- Tabs Content -->
                        <div class="tab-content" id="flashFolderTabsContent">
                            @php $isFirstContent = true; @endphp
                            @foreach ($flashFolderData as $folderName => $folderData)
                                @if (!isset($folderData['error']) && !empty($folderData))
                                    @php
                                        $tabId = 'tab-' . md5($folderName);
                                        $contentId = 'content-' . md5($folderName);
                                        $displayName = (strpos($folderName, 'MOD') !== false) ? 'MOD' : 'ORIGINAL';
                                        $tableId = 'table-' . md5($folderName);
                                        $tbodyId = 'tbody-' . md5($folderName);
                                        $paginationId = 'pagination-' . md5($folderName);
                                        $tableInfoId = 'tableinfo-' . md5($folderName);
                                        $entriesSelectId = 'entries-' . md5($folderName);
                                    @endphp
                                    <div class="tab-pane fade {{ $isFirstContent ? 'show active' : '' }}"
                                         id="{{ $contentId }}"
                                         role="tabpanel"
                                         aria-labelledby="{{ $tabId }}">

                                        <!-- Pagination Controls -->
                                        <div class="pagination-controls mb-3">
                                            <div class="entries-per-page">
                                                <label for="{{ $entriesSelectId }}" class="form-label mb-0">Show:</label>
                                                <select class="form-select form-select-sm"
                                                        id="{{ $entriesSelectId }}"
                                                        onchange="changeEntriesPerPage('{{ md5($folderName) }}', this.value)">
                                                    <option value="25" selected>25</option>
                                                    <option value="50">50</option>
                                                    <option value="100">100</option>
                                                    <option value="all">All</option>
                                                </select>
                                                <span>entries</span>
                                            </div>
                                        </div>

                                        <!-- Table -->
                                        <div class="table-responsive">
                                            <table class="table table-striped" id="{{ $tableId }}">
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
                                                <tbody id="{{ $tbodyId }}">
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
                                                        <tr data-index="{{ $index }}">
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

                                        <!-- Pagination -->
                                        <nav aria-label="Page navigation" id="{{ $paginationId }}">
                                            <ul class="pagination justify-content-center">
                                                <!-- Pagination will be dynamically generated -->
                                            </ul>
                                        </nav>

                                        <!-- Table info -->
                                        <div class="d-flex justify-content-between align-items-center mt-3">
                                            <div class="text-muted" id="{{ $tableInfoId }}">
                                                <!-- Table info will be dynamically updated -->
                                            </div>
                                        </div>
                                    </div>
                                    @php $isFirstContent = false; @endphp
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
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
        // Pagination functionality
        const tablesData = {};

        // Initialize pagination for each table
        document.addEventListener('DOMContentLoaded', function() {
            const tables = document.querySelectorAll('[id^="table-"]');
            tables.forEach(table => {
                const folderHash = table.id.replace('table-', '');
                initializeTable(folderHash);
            });
        });

        function initializeTable(folderHash) {
            const tbody = document.getElementById('tbody-' + folderHash);
            if (!tbody) return;

            const rows = Array.from(tbody.getElementsByTagName('tr'));

            tablesData[folderHash] = {
                allRows: rows,
                currentPage: 1,
                entriesPerPage: 25,
                totalEntries: rows.length
            };

            updateTable(folderHash);
        }

        function changeEntriesPerPage(folderHash, value) {
            if (!tablesData[folderHash]) return;

            tablesData[folderHash].entriesPerPage = value === 'all' ? tablesData[folderHash].totalEntries : parseInt(value);
            tablesData[folderHash].currentPage = 1;
            updateTable(folderHash);
        }

        function goToPage(folderHash, page) {
            if (!tablesData[folderHash]) return;

            tablesData[folderHash].currentPage = page;
            updateTable(folderHash);
        }

        function updateTable(folderHash) {
            const data = tablesData[folderHash];
            if (!data) return;

            const tbody = document.getElementById('tbody-' + folderHash);
            const pagination = document.getElementById('pagination-' + folderHash);
            const tableInfo = document.getElementById('tableinfo-' + folderHash);

            if (!tbody) return;

            // Clear current tbody
            tbody.innerHTML = '';

            // Calculate pagination
            const totalPages = Math.ceil(data.totalEntries / data.entriesPerPage);
            const startIndex = (data.currentPage - 1) * data.entriesPerPage;
            const endIndex = Math.min(startIndex + data.entriesPerPage, data.totalEntries);

            // Show relevant rows
            for (let i = startIndex; i < endIndex; i++) {
                if (data.allRows[i]) {
                    // Update row number
                    const firstCell = data.allRows[i].querySelector('td:first-child');
                    if (firstCell) {
                        firstCell.textContent = i + 1;
                    }
                    tbody.appendChild(data.allRows[i]);
                }
            }

            // Update pagination
            updatePagination(folderHash, totalPages);

            // Update table info
            if (tableInfo) {
                const showing = data.totalEntries === 0 ? 0 : startIndex + 1;
                tableInfo.textContent = `Showing ${showing} to ${endIndex} of ${data.totalEntries} entries`;
            }
        }

        function updatePagination(folderHash, totalPages) {
            const pagination = document.getElementById('pagination-' + folderHash);
            if (!pagination) return;

            const currentPage = tablesData[folderHash].currentPage;

            if (totalPages <= 1) {
                pagination.innerHTML = '';
                return;
            }

            let paginationHTML = '<ul class="pagination justify-content-center">';

            // Previous button
            paginationHTML += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="goToPage('${folderHash}', ${currentPage - 1}); return false;">Previous</a>
            </li>`;

            // Page numbers
            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(totalPages, currentPage + 2);

            if (startPage > 1) {
                paginationHTML += `<li class="page-item">
                    <a class="page-link" href="#" onclick="goToPage('${folderHash}', 1); return false;">1</a>
                </li>`;
                if (startPage > 2) {
                    paginationHTML += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                paginationHTML += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="goToPage('${folderHash}', ${i}); return false;">${i}</a>
                </li>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    paginationHTML += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                paginationHTML += `<li class="page-item">
                    <a class="page-link" href="#" onclick="goToPage('${folderHash}', ${totalPages}); return false;">${totalPages}</a>
                </li>`;
            }

            // Next button
            paginationHTML += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="goToPage('${folderHash}', ${currentPage + 1}); return false;">Next</a>
            </li>`;

            paginationHTML += '</ul>';
            pagination.innerHTML = paginationHTML;
        }
    </script>
</body>
</html>