/* File Manager - drag & drop upload interface (files and folders) */
(function () {
	'use strict';

	function init() {
	var cfg = window.fmUploadConfig || {};
	var root = document.getElementById('fm-upload');

	if (!root) {
		return;
	}

	var dropzone = document.getElementById('fm-dropzone');
	var filesInput = document.getElementById('fm-input-files');
	var folderInput = document.getElementById('fm-input-folder');
	var pickFiles = document.getElementById('fm-pick-files');
	var pickFolder = document.getElementById('fm-pick-folder');
	var overwrite = document.getElementById('fm-overwrite');
	var startBtn = document.getElementById('fm-start');
	var unzipBtn = document.getElementById('fm-start-unzip');
	var unzipInput = document.getElementById('fm-unzip-mode');
	var clearBtn = document.getElementById('fm-clear');
	var queueBody = document.querySelector('#fm-queue tbody');
	var progress = document.getElementById('fm-progress');
	var progressBar = document.getElementById('fm-progress-bar');
	var statusBox = document.getElementById('fm-status');
	var form = document.getElementById('upload_files');

	var items = [];
	var uploading = false;
	var cancelled = false;
	var unzipMode = false;

	function t(key) {
		return (cfg.lang && cfg.lang[key]) || key;
	}

	function format(template, values) {
		return String(template).replace(/\{(\d+)\}/g, function (match, index) {
			var value = values[parseInt(index, 10) - 1];
			return value === undefined ? match : value;
		});
	}

	function formatSize(bytes) {
		bytes = parseInt(bytes, 10) || 0;
		var units = ['B', 'KB', 'MB', 'GB', 'TB'];
		var i = 0;

		while (bytes >= 1024 && i < units.length - 1) {
			bytes = bytes / 1024;
			i++;
		}

		return (i > 1 ? bytes.toFixed(2) : bytes) + ' ' + units[i];
	}

	function basename(path) {
		var parts = path.split('/');
		return parts[parts.length - 1];
	}

	function setStatus(text) {
		statusBox.textContent = text || '';
	}

	function normalizePath(path, fallback) {
		var parts = String(path || fallback || '').replace(/\\/g, '/').split('/');
		var clean = [];

		for (var i = 0; i < parts.length; i++) {
			if (parts[i] === '' || parts[i] === '.') {
				continue;
			}
			if (parts[i] === '..') {
				continue;
			}
			clean.push(parts[i]);
		}

		if (!clean.length) {
			clean.push(fallback || 'file');
		}

		return clean.join('/');
	}

	function addFile(file, path) {
		if (!file) {
			return;
		}

		path = normalizePath(path || file.webkitRelativePath || file.name, file.name);

		for (var i = 0; i < items.length; i++) {
			if (items[i].path === path) {
				return;
			}
		}

		items.push({
			file: file,
			path: path,
			status: 'queued',
			progress: 0,
			message: '',
			xhr: null
		});
	}

	function addFileList(list) {
		if (!list) {
			return;
		}

		for (var i = 0; i < list.length; i++) {
			addFile(list[i]);
		}
	}

	function statusText(item) {
		switch (item.status) {
			case 'uploading':
				return t('uploading') + ' ' + item.progress + '%';
			case 'done':
				return item.message || t('done');
			case 'error':
				return item.message || t('error');
			case 'skipped':
				return item.message || t('skipped');
			default:
				return t('queued');
		}
	}

	function render() {
		queueBody.innerHTML = '';

		if (!items.length) {
			var emptyRow = document.createElement('tr');
			emptyRow.className = 'fm-queue__empty';
			var emptyCell = document.createElement('td');
			emptyCell.colSpan = 3;
			emptyCell.textContent = t('empty');
			emptyRow.appendChild(emptyCell);
			queueBody.appendChild(emptyRow);
			updateButtons();
			return;
		}

		for (var i = 0; i < items.length; i++) {
			var item = items[i];

			var row = document.createElement('tr');
			row.className = 'fm-queue__row fm-queue__row--' + item.status;
			row.setAttribute('data-index', i);

			var nameCell = document.createElement('td');
			nameCell.className = 'fm-queue__name';
			nameCell.textContent = item.path;
			row.appendChild(nameCell);

			var sizeCell = document.createElement('td');
			sizeCell.className = 'fm-queue__size';
			sizeCell.textContent = formatSize(item.file.size);
			row.appendChild(sizeCell);

			var statusCell = document.createElement('td');
			statusCell.className = 'fm-queue__status';
			statusCell.textContent = statusText(item);
			row.appendChild(statusCell);

			queueBody.appendChild(row);
		}

		updateButtons();
	}

	function updateRow(item) {
		var index = items.indexOf(item);
		var row = queueBody.querySelector('tr[data-index="' + index + '"]');

		if (!row) {
			return;
		}

		row.className = 'fm-queue__row fm-queue__row--' + item.status;
		var cell = row.querySelector('.fm-queue__status');

		if (cell) {
			cell.textContent = statusText(item);
		}
	}

	function hasPendingZip() {
		for (var i = 0; i < items.length; i++) {
			if ((items[i].status === 'queued' || items[i].status === 'error') && /\.zip$/i.test(items[i].path)) {
				return true;
			}
		}

		return false;
	}

	function pendingCount() {
		var count = 0;

		for (var i = 0; i < items.length; i++) {
			if (items[i].status === 'queued' || items[i].status === 'error') {
				count++;
			}
		}

		return count;
	}

	function updateButtons() {
		var pending = pendingCount();

		if (uploading) {
			startBtn.disabled = false;
			startBtn.value = t('cancel');
			clearBtn.disabled = true;
			pickFiles.disabled = true;
			pickFolder.disabled = true;
			overwrite.disabled = true;
			unzipBtn.disabled = true;
			unzipBtn.style.display = 'none';
			return;
		}

		startBtn.value = t('start') + (pending ? ' (' + pending + ')' : '');
		startBtn.disabled = pending === 0;
		clearBtn.disabled = items.length === 0;
		pickFiles.disabled = false;
		pickFolder.disabled = false;
		overwrite.disabled = false;
		unzipBtn.style.display = hasPendingZip() ? 'inline-block' : 'none';
		unzipBtn.disabled = pending === 0;
	}

	function setProgress(completed, total, currentPercent) {
		progress.className = 'fm-progress fm-progress--visible';

		var overall = 0;
		if (total > 0) {
			overall = Math.round(((completed + (currentPercent || 0) / 100) / total) * 100);
		}
		if (overall > 100) {
			overall = 100;
		}

		progressBar.style.width = overall + '%';
	}

	function startUpload(unzip) {
		if (uploading) {
			return;
		}

		unzip = !!unzip;

		if (unzipInput) {
			unzipInput.value = unzip ? '1' : '0';
		}

		var pending = [];

		for (var i = 0; i < items.length; i++) {
			if (items[i].status === 'queued' || items[i].status === 'error') {
				items[i].status = 'queued';
				items[i].progress = 0;
				items[i].message = '';
				items[i].xhr = null;
				pending.push(items[i]);
			}
		}

		if (!pending.length) {
			return;
		}

		cancelled = false;
		uploading = true;
		updateButtons();

		var total = pending.length;
		var completed = 0;
		var failed = 0;

		setProgress(0, total, 0);

		function finish() {
			if (cancelled) {
				return;
			}

			uploading = false;
			updateButtons();

			if (failed > 0) {
				setStatus(format(t('someFailed'), [failed, total]));
			} else {
				setStatus(t('allDone'));
			}
		}

		function next() {
			if (cancelled) {
				return;
			}

			var item = null;

			for (var i = 0; i < items.length; i++) {
				if (items[i].status === 'queued') {
					item = items[i];
					break;
				}
			}

			if (!item) {
				finish();
				return;
			}

			item.status = 'uploading';
			item.progress = 0;
			updateRow(item);
			setStatus(format(t('uploadingNow'), [item.path, completed + 1, total]));

			var data = new FormData();
			data.append('file', item.file, basename(item.path));
			data.append('path', item.path);
			data.append('my_post_key', cfg.postKey);
			data.append('overwrite', overwrite.checked ? '1' : '0');
			data.append('unzip', unzip ? '1' : '0');

			var xhr = new XMLHttpRequest();
			item.xhr = xhr;

			xhr.open('POST', cfg.url, true);
			xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

			xhr.upload.onprogress = function (event) {
				if (event.lengthComputable) {
					item.progress = Math.round((event.loaded / event.total) * 100);
					updateRow(item);
					setProgress(completed, total, item.progress);
				}
			};

			xhr.onload = function () {
				if (cancelled) {
					return;
				}

				var result = null;
				try {
					result = JSON.parse(xhr.responseText);
				} catch (e) {
					result = null;
				}

				if (xhr.status >= 200 && xhr.status < 300 && result && result.success) {
					item.status = 'done';
					item.progress = 100;
					item.message = result.message || t('done');
				} else if (result && result.skipped) {
					item.status = 'skipped';
					item.message = result.message || t('skipped');
				} else {
					item.status = 'error';
					item.message = (result && result.message) || t('genericError');
					failed++;
				}

				item.xhr = null;
				completed++;
				updateRow(item);
				setProgress(completed, total, 0);
				next();
			};

			xhr.onerror = function () {
				if (cancelled) {
					return;
				}

				item.status = 'error';
				item.message = t('genericError');
				item.xhr = null;
				failed++;
				completed++;
				updateRow(item);
				setProgress(completed, total, 0);
				next();
			};

			xhr.onabort = function () {
				item.xhr = null;
			};

			xhr.send(data);
		}

		next();
	}

	function cancelUpload() {
		if (!uploading) {
			return;
		}

		cancelled = true;
		uploading = false;

		for (var i = 0; i < items.length; i++) {
			if (items[i].status === 'uploading') {
				if (items[i].xhr) {
					try {
						items[i].xhr.abort();
					} catch (e) {
						// ignore
					}
				}
				items[i].status = 'queued';
				items[i].progress = 0;
				items[i].xhr = null;
				updateRow(items[i]);
			}
		}

		progressBar.style.width = '0%';
		setStatus(t('cancelled'));
		updateButtons();
	}

	function readEntries(entry, prefix, output) {
		return new Promise(function (resolve) {
			if (entry.isFile) {
				entry.file(function (file) {
					output.push({ file: file, path: prefix + entry.name });
					resolve();
				}, function () {
					resolve();
				});
				return;
			}

			if (entry.isDirectory) {
				var reader = entry.createReader();
				var children = [];

				var readBatch = function () {
					reader.readEntries(function (entries) {
						if (!entries.length) {
							var jobs = [];
							for (var i = 0; i < children.length; i++) {
								jobs.push(readEntries(children[i], prefix + entry.name + '/', output));
							}
							Promise.all(jobs).then(resolve, resolve);
							return;
						}

						for (var j = 0; j < entries.length; j++) {
							children.push(entries[j]);
						}
						readBatch();
					}, function () {
						resolve();
					});
				};

				readBatch();
				return;
			}

			resolve();
		});
	}

	function handleDrop(event) {
		event.preventDefault();
		event.stopPropagation();
		dropzone.classList.remove('fm-dropzone--active');

		if (uploading) {
			return;
		}

		var transfer = event.dataTransfer;

		if (!transfer) {
			return;
		}

		var entries = [];
		var i;

		if (transfer.items && transfer.items.length && typeof transfer.items[0].webkitGetAsEntry === 'function') {
			for (i = 0; i < transfer.items.length; i++) {
				var entry = transfer.items[i].webkitGetAsEntry();
				if (entry) {
					entries.push(entry);
				}
			}
		}

		if (entries.length) {
			setStatus(t('reading'));
			var output = [];
			var jobs = [];

			for (i = 0; i < entries.length; i++) {
				jobs.push(readEntries(entries[i], '', output));
			}

			Promise.all(jobs).then(function () {
				for (var j = 0; j < output.length; j++) {
					addFile(output[j].file, output[j].path);
				}
				setStatus('');
				render();
			}, function () {
				setStatus('');
				render();
			});

			return;
		}

		if (transfer.files) {
			addFileList(transfer.files);
			render();
		}
	}

	if (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var mode = unzipMode;
			unzipMode = false;

			if (uploading) {
				cancelUpload();
			} else {
				startUpload(mode);
			}
		});

		startBtn.addEventListener('click', function () {
			unzipMode = false;
		});

		unzipBtn.addEventListener('click', function () {
			unzipMode = true;
		});
	}

	pickFiles.addEventListener('click', function (event) {
		event.preventDefault();
		event.stopPropagation();
		if (!uploading) {
			filesInput.click();
		}
	});

	pickFolder.addEventListener('click', function (event) {
		event.preventDefault();
		event.stopPropagation();
		if (!uploading) {
			folderInput.click();
		}
	});

	dropzone.addEventListener('click', function (event) {
		if (uploading || event.target === pickFiles || event.target === pickFolder) {
			return;
		}
		filesInput.click();
	});

	dropzone.addEventListener('keydown', function (event) {
		if ((event.key === 'Enter' || event.key === ' ') && !uploading) {
			event.preventDefault();
			filesInput.click();
		}
	});

	var dragDepth = 0;

	dropzone.addEventListener('dragenter', function (event) {
		event.preventDefault();
		dragDepth++;
		dropzone.classList.add('fm-dropzone--active');
	});

	dropzone.addEventListener('dragover', function (event) {
		event.preventDefault();
		event.dataTransfer.dropEffect = 'copy';
	});

	dropzone.addEventListener('dragleave', function (event) {
		event.preventDefault();
		dragDepth--;
		if (dragDepth <= 0) {
			dragDepth = 0;
			dropzone.classList.remove('fm-dropzone--active');
		}
	});

	dropzone.addEventListener('drop', handleDrop);

	filesInput.addEventListener('change', function () {
		addFileList(filesInput.files);
		filesInput.value = '';
		render();
	});

	folderInput.addEventListener('change', function () {
		addFileList(folderInput.files);
		folderInput.value = '';
		render();
	});

	clearBtn.addEventListener('click', function () {
		if (uploading) {
			return;
		}

		items = [];
		progress.className = 'fm-progress';
		progressBar.style.width = '0%';
		setStatus('');
		render();
	});

	if (cfg.lang && cfg.lang.maxSize) {
		var hint = dropzone.querySelector('.fm-dropzone__hint');
		if (hint) {
			hint.textContent = hint.textContent + ' ' + cfg.lang.maxSize;
		}
	}

	render();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
