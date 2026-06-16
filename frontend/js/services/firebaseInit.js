// firebaseInit.js
// Initialize Firebase using a configuration template.
// REPLACE WITH YOUR ACTUAL FIREBASE PROJECT CREDENTIALS

const firebaseConfig = {
  apiKey: "AIzaSyBdt8ybG2EHjmbbr202Qi7d0xGgVj0s9oc",
  authDomain: "evelens-eyewear.firebaseapp.com",
  projectId: "evelens-eyewear",
  storageBucket: "evelens-eyewear.firebasestorage.app",
  messagingSenderId: "620243813750",
  appId: "1:620243813750:web:0ada14a93cafaa4a3c6657",
  measurementId: "G-JTZ1BBMS1D"
};

// Only initialize if the user has replaced placeholder values
if (typeof firebase !== 'undefined') {
    if (firebaseConfig.apiKey !== "YOUR_API_KEY") {
        firebase.initializeApp(firebaseConfig);
        console.log("Firebase Auth initialized successfully.");
    } else {
        console.warn("Firebase config has placeholders. Please configure firebaseInit.js with your project credentials to enable Google Sign-In.");
    }
} else {
    console.error("Firebase SDK not loaded. Cannot initialize Firebase.");
}
