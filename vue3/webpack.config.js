const { VueLoaderPlugin } = require('vue-loader');
const path = require('path');

module.exports = (env, options) => {

    const isProduction = options.mode === 'production';

    const config = {
        entry: './main.ts',
        output: {
            path: path.resolve(__dirname, '../amd/src'),
            publicPath: '/dist/',
            filename: 'app.js',
            chunkFilename: "[id].app.min.js?v=[hash]",
            libraryTarget: 'amd',
        },
        module: {
            rules: [
                {
                  test: /\.vue$/,
                  loader: 'vue-loader'
                },
                {
                  test: /\.ts$/,
                  loader: 'ts-loader',
                  options: {
                    appendTsSuffixTo: [/\.vue$/],
                    transpileOnly: true
                  }
                }
              ]
        },
        resolve: {
            alias: {
                'vue$': 'vue/dist/vue.esm-bundler.js'
            },
            extensions: ['.js', '.ts', '.vue', '.json']
        },
        devServer: {
            historyApiFallback: true,
            noInfo: true,
            overlay: true,
            headers: {
                'Access-Control-Allow-Origin': '*'
            },
            disableHostCheck: true,
            https: true,
            public: 'https://127.0.0.1:8080',
            hot: true,
        },
        performance: {
            hints: false
        },
        devtool: 'eval',
        plugins: [
            new VueLoaderPlugin(),
        ],
        watchOptions: {
            ignored: /node_modules/
        },
        externals: {
            'core/ajax': { amd: 'core/ajax' },
            'core/localstorage': { amd: 'core/localstorage' },
            'core/notification': { amd: 'core/notification' }
        }
    };

    if (isProduction) {
        config.devtool = false;
    }

    return config;
};
