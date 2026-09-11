(() => {
  'use strict';

  const DB_NAME = 'restaurant-application-media-v1';
  const DB_VERSION = 1;
  const STORE_NAME = 'attachments';
  const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v'];
  const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

  function extension(name = '') {
    const parts = String(name).toLowerCase().split('.');
    return parts.length > 1 ? parts.pop() : '';
  }

  function requestPromise(request) {
    return new Promise((resolve, reject) => {
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error || new Error('Browser storage request failed.'));
    });
  }

  function transactionPromise(transaction) {
    return new Promise((resolve, reject) => {
      transaction.oncomplete = () => resolve();
      transaction.onerror = () => reject(transaction.error || new Error('Browser storage transaction failed.'));
      transaction.onabort = () => reject(transaction.error || new Error('Browser storage transaction was cancelled.'));
    });
  }

  function openDatabase() {
    if (!('indexedDB' in window)) {
      return Promise.reject(new Error('This browser does not support private local media storage.'));
    }
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      request.onupgradeneeded = () => {
        const database = request.result;
        const store = database.objectStoreNames.contains(STORE_NAME)
          ? request.transaction.objectStore(STORE_NAME)
          : database.createObjectStore(STORE_NAME, {keyPath: 'id'});
        if (!store.indexNames.contains('resumeId')) {
          store.createIndex('resumeId', 'resumeId', {unique: false});
        }
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error || new Error('Private local media storage could not be opened.'));
      request.onblocked = () => reject(new Error('Private local media storage is blocked by another open page.'));
    });
  }

  async function deleteResumeMedia(resumeId) {
    const database = await openDatabase();
    try {
      const transaction = database.transaction(STORE_NAME, 'readwrite');
      const done = transactionPromise(transaction);
      const store = transaction.objectStore(STORE_NAME);
      const index = store.index('resumeId');
      await new Promise((resolve, reject) => {
        const cursorRequest = index.openCursor(IDBKeyRange.only(String(resumeId)));
        cursorRequest.onsuccess = () => {
          const cursor = cursorRequest.result;
          if (!cursor) {
            resolve();
            return;
          }
          cursor.delete();
          cursor.continue();
        };
        cursorRequest.onerror = () => reject(cursorRequest.error || new Error('Existing media could not be cleared.'));
      });
      await done;
    } finally {
      database.close();
    }
  }

  async function saveResumeMedia(resumeId, payload = {}) {
    const normalizedResumeId = String(resumeId || '').trim();
    if (!normalizedResumeId) throw new Error('A submission identifier is required for media storage.');

    await deleteResumeMedia(normalizedResumeId);
    const records = [];
    const createdAt = new Date().toISOString();

    if (payload.video?.file instanceof Blob) {
      records.push({
        id: `${normalizedResumeId}:video:0`,
        resumeId: normalizedResumeId,
        kind: 'video',
        position: 0,
        name: payload.video.file.name || 'application-video',
        mimeType: payload.video.file.type || 'application/octet-stream',
        size: payload.video.file.size || 0,
        duration: Number(payload.video.duration || 0),
        blob: payload.video.file,
        createdAt,
      });
    }

    Array.from(payload.photos || []).slice(0, 5).forEach((file, index) => {
      if (!(file instanceof Blob)) return;
      records.push({
        id: `${normalizedResumeId}:photo:${index}`,
        resumeId: normalizedResumeId,
        kind: 'photo',
        position: index,
        name: file.name || `application-photo-${index + 1}`,
        mimeType: file.type || 'application/octet-stream',
        size: file.size || 0,
        duration: null,
        blob: file,
        createdAt,
      });
    });

    if (!records.length) return [];

    const database = await openDatabase();
    try {
      const transaction = database.transaction(STORE_NAME, 'readwrite');
      const done = transactionPromise(transaction);
      const store = transaction.objectStore(STORE_NAME);
      records.forEach(record => store.put(record));
      await done;
      return records.map(({blob, ...metadata}) => metadata);
    } finally {
      database.close();
    }
  }

  async function getResumeMedia(resumeId) {
    const database = await openDatabase();
    try {
      const transaction = database.transaction(STORE_NAME, 'readonly');
      const done = transactionPromise(transaction);
      const index = transaction.objectStore(STORE_NAME).index('resumeId');
      const records = await requestPromise(index.getAll(IDBKeyRange.only(String(resumeId))));
      await done;
      return Array.from(records || []).sort((a, b) => {
        if (a.kind !== b.kind) return a.kind === 'video' ? -1 : 1;
        return Number(a.position || 0) - Number(b.position || 0);
      });
    } finally {
      database.close();
    }
  }

  function getVideoDuration(file) {
    return new Promise((resolve, reject) => {
      const video = document.createElement('video');
      const url = URL.createObjectURL(file);
      const cleanup = () => {
        URL.revokeObjectURL(url);
        video.removeAttribute('src');
        video.load();
      };
      video.preload = 'metadata';
      video.onloadedmetadata = () => {
        const duration = Number(video.duration);
        cleanup();
        if (!Number.isFinite(duration) || duration <= 0) {
          reject(new Error('The selected video duration could not be verified.'));
          return;
        }
        resolve(duration);
      };
      video.onerror = () => {
        cleanup();
        reject(new Error('The selected video could not be opened by this browser.'));
      };
      video.src = url;
    });
  }

  async function validateVideo(file, maximumSeconds = 30) {
    if (!(file instanceof File)) throw new Error('Select a video file.');
    const typeAllowed = String(file.type || '').startsWith('video/') || VIDEO_EXTENSIONS.includes(extension(file.name));
    if (!typeAllowed) throw new Error('Select an MP4, WebM, MOV, or M4V video.');
    const duration = await getVideoDuration(file);
    if (duration > Number(maximumSeconds) + 0.05) {
      throw new Error(`Video must be ${maximumSeconds} seconds or shorter. The selected video is ${duration.toFixed(1)} seconds.`);
    }
    return {duration};
  }

  function validatePhotos(files, maximumPhotos = 5) {
    const list = Array.from(files || []);
    if (list.length > maximumPhotos) throw new Error(`Select no more than ${maximumPhotos} photos.`);
    const invalid = list.find(file => {
      if (!(file instanceof File)) return true;
      return !(String(file.type || '').startsWith('image/') || PHOTO_EXTENSIONS.includes(extension(file.name)));
    });
    if (invalid) throw new Error('Photos must be JPEG, PNG, WebP, HEIC, or HEIF images.');
    return list;
  }

  function formatBytes(bytes) {
    const value = Number(bytes || 0);
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${Math.max(1, Math.round(value / 1024))} KB`;
    return `${(value / (1024 * 1024)).toFixed(value >= 10 * 1024 * 1024 ? 0 : 1)} MB`;
  }

  window.RestaurantFormMedia = {
    maximumVideoSeconds: 30,
    maximumPhotos: 5,
    saveResumeMedia,
    getResumeMedia,
    deleteResumeMedia,
    validateVideo,
    validatePhotos,
    formatBytes,
  };
})();
