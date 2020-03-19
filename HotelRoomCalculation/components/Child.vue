<template>
  <div @mouseover="visible = true" @mouseout="visible = false" class="child-age-wrap">
    <div class="label">{{index+1}}. Children</div>
    <a class="btn-delete fe fe-x" v-show="visible" href="#" @click.prevent="deleteItem"></a>
    <select class="age-select" v-model="childAge" @change="updateAge">
      <option v-for="(n,i) in 18" :key="i" :value="i">{{ i == 0 ? '< ' :''}}{{i}} {{tr.get('children.years')}}</option>
    </select>
  </div>
</template>

<script>
  export default {
    data() {
      return {
        childAge: this.child,
        visible: false
      }
    },
    props: ['child', 'index'],
    methods: {
      updateAge(event) {
        this.$emit('updateAge', event.target.value);
      },
      deleteItem() {
        this.$emit('deleteItem')
      }
    }
  }
</script>

<style scoped>
  .child-age-wrap {
    position: relative;
    background: #fff;
    border-radius: 3px;
    border: 1px solid #D2DDEC;
    display: inline-block;
    margin-right: 10px;
  }

  .child-age-wrap .label {
    background: #d2dded;
    font-size: 8px;
    padding: 2px 5px;
    line-height: 1em;
  }

  .child-age-wrap .age-select {
    background: transparent;
    border: none;
    margin: 2px;
  }

  .btn-delete {
    background: red;
    color: #fff;
    font-size: 7px;
    width: 10px;
    line-height: 1em;
    height: 10px;
    border-radius: 20px;
    position: absolute;
    right: 3px;
    top: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    transform: translateY(-50%);
    box-shadow: 0 2px 8px -3px #000;
  }
</style>
