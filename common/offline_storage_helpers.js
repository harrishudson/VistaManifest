/* Copyright (c) Harris Hudson 2024 */

// Implementaiton of offline key/value storage for small short lived data

export function getCache(key) {
 if (Math.random() < 0.1) 
  cleanCache()

 let key_hash = btoa(key)
 if (key_hash in localStorage) {
  let obj = JSON.parse(localStorage[key_hash])
  let now = parseInt(Date.now())
  if ((now - obj.date_stored) > (2 * 60 * 1000)) // 2 minutes
   return null
  else 
   return obj.src
 }
 return null
}

export function setCache(key, src) {
 if (!key)
  return
 if (!src)
  return
 let key_hash = btoa(key)
 let obj = {
  date_stored: parseInt(Date.now()),
  src: src
 }
 localStorage[key_hash] = JSON.stringify(obj)
 
 if (Math.random() < 0.1)
  cleanCache()
}

export function cleanCache() {
 let now = parseInt(Date.now())
 let keys = Object.keys(localStorage)
 for (let k in keys) {
  let s = localStorage[keys[k]]
  try {
   let obj = JSON.parse(s)
   if ((now - obj.date_stored) > (2 * 60 * 1000)) // 2 minutes
    localStorage.removeItem(keys[k])
  } catch(e) { }
 }
}


// Implementaiton of offline key/value storage for large data

export function openDatabase(dbName, storeName) {
 return new Promise((resolve, reject) => {
  const request = indexedDB.open(dbName, 1)
  request.onupgradeneeded = (event) => {
   const db = event.target.result
   if (!db.objectStoreNames.contains(storeName)) {
    db.createObjectStore(storeName, { keyPath: "key" }); // Use "key" as the primary key
   }
  }
  request.onsuccess = (event) => resolve(event.target.result)
  request.onerror = (event) => reject(event.target.error)
 })
}

export function storeData(dbName, storeName, key, value) {
 return openDatabase(dbName, storeName).then((db) => {
  return new Promise((resolve, reject) => {
   const transaction = db.transaction(storeName, "readwrite")
   const store = transaction.objectStore(storeName)
   const request = store.put({ key, value })
   request.onsuccess = () => resolve()
   request.onerror = (event) => reject(event.target.error)
  })
 })
}

export function getData(dbName, storeName, key) {
 return openDatabase(dbName, storeName).then((db) => {
  return new Promise((resolve, reject) => {
   const transaction = db.transaction(storeName, "readonly")
   const store = transaction.objectStore(storeName)
   const request = store.get(key)
   request.onsuccess = () => resolve(request.result?.value)
   request.onerror = (event) => reject(event.target.error)
  })
 })
}

export function clearAllData(dbName, storeName) {
 return openDatabase(dbName, storeName).then((db) => {
  return new Promise((resolve, reject) => {
   const transaction = db.transaction(storeName, "readwrite")
   const store = transaction.objectStore(storeName)
   const request = store.clear()
   request.onsuccess = () => resolve()
   request.onerror = (event) => reject(event.target.error)
  })
 })
}

export async function fetchData(db, storename, key) {
 try {
  const value = await getData(db, storename, key)
  return value
 } catch (error) {
  console.error("Error fetching data:", error)
 }
}
