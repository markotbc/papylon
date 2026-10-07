const { src, dest, watch, series, parallel } = require("gulp");
const sass = require("gulp-sass")(require("sass"));
const autoprefixer = require("gulp-autoprefixer");
const cleanCSS = require("gulp-clean-css");
const sourcemaps = require("gulp-sourcemaps");
const uglify = require("gulp-uglify");
const browserSync = require("browser-sync").create();

const paths = {
  scss: {
    entry: "assets/src/scss/main.scss",
    src: "assets/src/scss/**/*.scss",
    dest: "assets/dest/css",
  },
  js: { src: "assets/src/js/**/*.js", dest: "assets/dest/js" },
  shortcodes: {
    js: "inc/shortcodes/**/assets/**/*.js",
    css: "inc/shortcodes/**/assets/**/*.css",
  },
  php: "**/*.php",
};

function style() {
  return src(paths.scss.entry, { allowEmpty: true })
    .pipe(sourcemaps.init())
    .pipe(sass({ includePaths: ["assets/src/scss"] }).on("error", sass.logError))
    .pipe(autoprefixer())
    .pipe(cleanCSS({ level: 1 }))
    .pipe(sourcemaps.write("."))
    .pipe(dest(paths.scss.dest))
    .pipe(browserSync.stream());
}

function scripts() {
  return src(paths.js.src, { allowEmpty: true })
    .pipe(dest(paths.js.dest))
    .pipe(browserSync.stream());
}

function productionStyle() {
  return src(paths.scss.entry, { allowEmpty: true })
    .pipe(sass({ includePaths: ["assets/src/scss"] }).on("error", sass.logError))
    .pipe(autoprefixer())
    .pipe(cleanCSS({ level: 1 }))
    .pipe(dest(paths.scss.dest));
}

function productionScripts() {
  return src(paths.js.src, { allowEmpty: true })
    .pipe(uglify())
    .pipe(dest(paths.js.dest));
}

function serve() {
  browserSync.init({
    proxy: "https://papylon.local",
    notify: false,
    open: false,
  });
  watch(paths.scss.src, style);
  watch(paths.js.src, scripts);
  watch(paths.shortcodes.js).on("change", browserSync.reload);
  watch(paths.shortcodes.css).on("change", browserSync.reload);
  watch(paths.php).on("change", browserSync.reload);
}

exports.style = style;
exports.scripts = scripts;
exports.serve = serve;
exports.build = parallel(style, scripts);
exports["build:production"] = parallel(productionStyle, productionScripts);
exports.default = series(parallel(style, scripts), serve);