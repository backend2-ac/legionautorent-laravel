import {defineConfig} from 'vite';
export default defineConfig({publicDir:false,base:'/static/build/',build:{outDir:'public/static/build',emptyOutDir:true,manifest:true,target:['chrome64','firefox67','safari11.1','edge79'],minify:'terser',terserOptions:{ecma:2017,compress:{passes:3}},rollupOptions:{input:{main:'frontend/main.js'}}}});
